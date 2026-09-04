<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Attribute;
use App\Models\Product;
use App\Models\ProductionItem;
use App\Models\ProductionStage;
use App\Models\WarehouseBridge;
use App\Models\WarehouseCodeMap;
use App\Models\WarehouseLog;
use App\Models\WarehouseReservation;
use App\Models\WarehouseSheetRow;
use App\Models\WarehouseSourceStock;
use App\Services\Production\CodeGrouper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Produkcja — wlasny dzial w menu (miedzy Argo HQ a Argo PIM).
 *
 * Ekran glowny: lista kodow produkcyjnych. Jeden kod = jeden wiersz, bo w PIM
 * ten sam `product_code` powtarza sie dla kazdego auta, do ktorego czesc pasuje
 * (np. 18.201 to 21 wpisow) — z punktu widzenia produkcji to ciagle jedna
 * sztuka do zrobienia.
 *
 * Wszystkie wiersze ida do przegladarki naraz, filtrowanie i sortowanie robi
 * DataGrid po stronie klienta — bez paginacji i bez round-tripow.
 */
class ProductionController extends Controller
{
    /**
     * Kolumny arkusza, ktore wolno poprawic recznie z gridu Magazyn → Tabela.
     * Bialalista, nie „wszystko poza kilkoma": bez niej edycja pozwalalaby
     * pisac po `product_code` i `row_no`, czyli po tozsamosci wiersza.
     *
     * `quantity_total` tu nie ma swiadomie — to suma, liczy ja serwer.
     */
    private const SHEET_EDITABLE = [
        'place_1', 'place_2', 'place_3', 'place_4', 'place_5', 'place_6',
        'qty_1', 'qty_2', 'qty_3', 'qty_4', 'qty_5', 'qty_6',
        'steel_team', 'uwagi', 'wymiar', 'waga',
    ];

    /** Nazwy pol arkusza w logach — „qty_3" nikomu nic nie mowi po tygodniu. */
    private const SHEET_FIELD_LABELS = [
        'place_1' => 'Miejsce 1', 'qty_1' => 'Ilość 1',
        'place_2' => 'Miejsce 2', 'qty_2' => 'Ilość 2',
        'place_3' => 'Miejsce 3', 'qty_3' => 'Ilość 3',
        'place_4' => 'Miejsce 4', 'qty_4' => 'Ilość 4',
        'place_5' => 'Miejsce 5', 'qty_5' => 'Ilość 5',
        'place_6' => 'Miejsce 6', 'qty_6' => 'Ilość 6',
        'steel_team' => 'steel team', 'uwagi' => 'Uwagi',
        'wymiar' => 'WYMIAR', 'waga' => 'WAGA',
    ];

    /**
     * Znaczniki przestawiane z gridu: nazwa z frontu => kolumna w bazie.
     * Bialalista — bez niej `setFlag` pozwalalby pisac po dowolnej kolumnie.
     *
     * Etapow tu nie ma: od czasu slownika `production_stages` etap wynika
     * wylacznie ze sprzedazy i przelicza go StageAssigner, a nie klikniecie.
     */
    private const FLAGS = [
        'project' => 'has_project',
        'team_steel' => 'team_steel',
        'brak_zestawu' => 'brak_zestawu',
        'projekty_gotowe' => 'projekty_gotowe',
    ];

    /**
     * Katalog kodow: jeden wiersz na `product_code`, kluczowany kodem.
     * Sam katalog, bez danych produkcyjnych — te dokleja `index()`.
     *
     * Wspolny dla Produkcji i Magazynu, bo obu ekranom chodzi o to samo:
     * zwinac powtorzenia kodu z `products` do jednej pozycji.
     */
    private function codeCatalog(): Collection
    {
        // Atrybut „Materiał" (Stal/Aluminium) — dociagany tak samo jak na liscie produktow.
        $materialValues = Attribute::with('values')->where('slug', 'material')->first()?->values ?? collect();
        $materialValueIds = $materialValues->pluck('id')->all();
        $materialLabels = $materialValues->mapWithKeys(fn ($value) => [$value->slug => $value->name])->all();

        return Product::query()
            ->select('id', 'product_code', 'name')
            ->with(['attributeValues' => fn ($query) => $query->whereIn('attribute_values.id', $materialValueIds)])
            ->orderBy('product_code')
            ->orderBy('id')
            ->get()
            ->groupBy('product_code')
            ->map(function ($group) use ($materialLabels) {
                // Reprezentant kodu = najstarszy produkt (najnizsze id) — po nim bierzemy nazwe.
                $product = $group->first();
                $slug = $product->attributeValues->first()?->slug;

                return [
                    'product_id' => $product->id,
                    'product_code' => $product->product_code,
                    // Nazwy w bazie bywaja z encjami HTML (&quot;) — te same, co na liscie produktow.
                    'name' => htmlspecialchars_decode((string) $product->name),
                    'material' => $slug === null ? '' : ($materialLabels[$slug] ?? $slug),
                    // Ile produktow (aut) kryje sie pod tym kodem — sygnal, ze nazwa to jeden z wielu wariantow.
                    'variants' => $group->count(),
                    // Nazwy wszystkich aut pod tym kodem — do ramki po najechaniu na „+N".
                    'variant_names' => $group
                        ->map(fn ($p) => htmlspecialchars_decode((string) $p->name))
                        ->values()
                        ->all(),
                ];
            });
    }

    /**
     * Lista kodow produkcyjnych (bez powtorzen) do gridu produkcji.
     */
    public function index(Request $request, CodeGrouper $grouper): Response
    {
        $stages = ProductionStage::orderBy('position')->orderBy('id')->get();

        // Dane produkcyjne — tylko dla kodow, na ktorych cos ustawiono albo wgrano.
        $items = ProductionItem::get(array_merge(
            ['product_code', 'sales_12m', 'stage_id', 'excluded'],
            array_values(self::FLAGS)
        ))->keyBy('product_code');

        // Kod wariantu => trzon. Tylko grupy zatwierdzone i tylko zaznaczone
        // warianty — odpiete zostaja osobnymi wierszami.
        $groupMap = $grouper->activeMap();

        // Kody wykluczone w Ustawieniach wypadaja PRZED grupowaniem — nie sa
        // wierszem i nie doliczaja sie do sumy zadnej grupy.
        $excluded = $items->filter(fn ($item) => (bool) $item->excluded)->keys()->flip();

        $rows = $this->codeCatalog()
            ->reject(fn ($row, $code) => $excluded->has($code))
            ->map(function (array $row) use ($items) {
                $item = $items[$row['product_code']] ?? null;

                // Sprzedaz 12M z raportu Subiekta. Kod bez wiersza w raporcie = 0.
                $row['sales_12m'] = (int) ($item?->sales_12m ?? 0);
                $row['stage_id'] = $item?->stage_id;
                // Warianty wciagniete do tego wiersza (wypelniane nizej).
                $row['group_codes'] = [];

                // Znaczniki lecą pod nazwami z frontu — dodanie kolejnego to jeden wpis w FLAGS.
                foreach (self::FLAGS as $key => $column) {
                    $row[$key] = (bool) ($item?->{$column} ?? false);
                }

                return $row;
            });

        // Skladamy warianty w trzony: sprzedaz sie sumuje, wiersz wariantu znika.
        foreach ($groupMap as $variant => $trunk) {
            if (! $rows->has($variant) || ! $rows->has($trunk) || $variant === $trunk) {
                continue;
            }

            $variantRow = $rows[$variant];
            $trunkRow = $rows[$trunk];

            $trunkRow['sales_12m'] += $variantRow['sales_12m'];
            $trunkRow['group_codes'][] = [
                'code' => $variant,
                'sales_12m' => $variantRow['sales_12m'],
            ];

            // Wiersz pokrywa teraz takze auta wciagnietego wariantu — licznik przy
            // nazwie i lista w ramce musza to odzwierciedlac.
            $trunkRow['variants'] += $variantRow['variants'];
            $trunkRow['variant_names'] = array_merge($trunkRow['variant_names'], $variantRow['variant_names']);

            $rows[$trunk] = $trunkRow;
            $rows->forget($variant);
        }

        $rows = $rows->values();

        return Inertia::render('Production/Index', [
            'rows' => $rows,
            'stages' => $stages->map(fn (ProductionStage $stage) => [
                'id' => $stage->id,
                'name' => $stage->name,
                'color' => $stage->color,
            ])->values(),
        ]);
    }

    /**
     * Raporty produkcyjne — na razie pusty ekran, zeby pozycja w menu prowadzila
     * gdziekolwiek zamiast wywalac 404.
     */
    public function reports(Request $request): Response
    {
        return Inertia::render('Production/Reports');
    }

    /**
     * Magazyn — na razie jedna zakladka „Magazyn M3R" z pelna lista kodow.
     *
     * Swiadomie BEZ wykluczen i bez grupowania z Produkcji: tam chodzi o to,
     * co trzeba zaprojektowac, a tu o to, co fizycznie lezy na polce — kod
     * wykluczony z produkcji dalej moze miec stan.
     *
     * Kolumny ilosci sa puste do czasu, az poleca do nich odczyty: „Stan M3R"
     * ze wskazanego magazynu Subiekta GT przez ARGO Bridge, „Tabela" z arkusza
     * Google prowadzonego recznie.
     *
     * `unmapped` to drugi kubelek: wiersze ZE ZRODEL, ktore nie maja pary w PIM
     * — kod z arkusza albo z Subiekta, ktorego tu nie ma, albo pasujacy do
     * wiecej niz jednego produktu. Kolizje kodow miedzy arkuszem a Subiektem sa
     * pewne, wiec to staly kubelek roboczy, a nie lista bledow. Pusta tablica,
     * dopoki nie ma czego zaciagac.
     */
    public function warehouse(Request $request): Response
    {
        $catalog = $this->codeCatalog();

        // Dopasowanie po kodzie idzie bez wzgledu na wielkosc liter — w Subiekcie
        // i w arkuszu te same symbole bywaja pisane roznie.
        $byUpperCode = $catalog->keys()->mapWithKeys(fn ($code) => [mb_strtoupper($code) => $code]);

        // Reczne mapowania. Wygrywaja z dopasowaniem po kodzie: jesli ktos przypial
        // kod swiadomie, automat nie ma prawa go przebic.
        $manual = WarehouseCodeMap::get(['product_code', 'source', 'source_code'])
            ->keyBy(fn ($row) => $row->source.'|'.mb_strtoupper($row->source_code));

        // Oba zrodla sprowadzone do jednego ksztaltu: kod, nazwa, ilosc. Dalej
        // traktujemy je identycznie, wiec regula dopasowania jest jedna i te same
        // wiersze nie moga rozejsc sie miedzy ekranem Tabeli a lista M3R.
        $sourceRows = collect();

        foreach (WarehouseSourceStock::where('source', 'gt')->orderBy('source_code')->get() as $item) {
            $sourceRows->push([
                'source' => 'gt',
                'code' => $item->source_code,
                'name' => $item->name,
                'qty' => (float) $item->quantity,
            ]);
        }

        // Arkusz inwentury — ten sam, ktory pokazuje ekran „Tabela".
        foreach (WarehouseSheetRow::where('sheet', WarehouseSheetRow::DEFAULT_SHEET)->orderBy('product_code')->get() as $row) {
            $sourceRows->push([
                'source' => 'sheet',
                'code' => $row->product_code,
                'name' => null,
                'qty' => (float) $row->quantity_total,
            ]);
        }

        // Ilosci i kody zrodlowe zwiniete per kod PIM; osobno to, co nie trafilo nigdzie.
        $sums = [];      // [product_code][source] => suma ilosci
        $attached = [];  // [product_code][source] => [['code' =>, 'auto' =>], ...]
        $unmapped = [];
        $hasSnapshot = [];

        foreach ($sourceRows as $item) {
            $hasSnapshot[$item['source']] = true;

            $key = $item['source'].'|'.mb_strtoupper($item['code']);
            $target = $manual[$key]->product_code ?? ($byUpperCode[mb_strtoupper($item['code'])] ?? null);

            if ($target === null || ! $catalog->has($target)) {
                $unmapped[] = [
                    'key' => $item['source'].':'.$item['code'],
                    'source_code' => $item['code'],
                    'source' => WarehouseCodeMap::SOURCES[$item['source']] ?? $item['source'],
                    'name' => $item['name'],
                    'quantity' => $item['qty'],
                    'reason' => $target === null
                        ? 'Nie ma takiego kodu w PIM'
                        : "Przypisany do {$target}, ale takiego kodu nie ma w katalogu",
                ];
                continue;
            }

            $sums[$target][$item['source']] = ($sums[$target][$item['source']] ?? 0) + $item['qty'];
            $attached[$target][$item['source']][] = [
                'code' => $item['code'],
                // Reczne przypisanie zaznaczamy, bo tylko takie da sie odpiac.
                'auto' => ! isset($manual[$key]),
            ];
        }

        // Reczne przypisania bez wiersza w stanie tez maja byc widoczne — inaczej
        // dopiecie kodu wygladaloby jak klikniecie w prozne, dopoki nie przyjdzie paczka.
        foreach ($manual as $row) {
            $seen = collect($attached[$row->product_code][$row->source] ?? [])
                ->contains(fn ($entry) => mb_strtoupper($entry['code']) === mb_strtoupper($row->source_code));

            if (! $seen) {
                $attached[$row->product_code][$row->source][] = ['code' => $row->source_code, 'auto' => false];
            }
        }

        $rows = $catalog
            ->map(function (array $row) use ($sums, $attached, $hasSnapshot) {
                $code = $row['product_code'];

                foreach (array_keys(WarehouseCodeMap::SOURCES) as $source) {
                    $row['map_'.$source] = $attached[$code][$source] ?? [];
                }

                // Zero pokazujemy TYLKO wtedy, gdy migawka zrodla w ogole przyszla:
                // paczka jest pelnym stanem magazynu, wiec brak pozycji znaczy „nie ma
                // na stanie". Bez migawki zostaje kreska — „nie wiem" nie moze udawac zera.
                $row['stock'] = $sums[$code]['gt'] ?? (isset($hasSnapshot['gt']) ? 0 : null);
                $row['sheet_qty'] = $sums[$code]['sheet'] ?? (isset($hasSnapshot['sheet']) ? 0 : null);

                return $row;
            })
            ->values();

        return Inertia::render('Production/Warehouse', [
            'rows' => $rows,
            'unmapped' => $unmapped,
            'sources' => WarehouseCodeMap::SOURCES,
            // Bez tego front nie odroznilby „jeszcze nic nie przyszlo" od „przyszlo i sa zera".
            'has_stock' => [
                'gt' => isset($hasSnapshot['gt']),
                'sheet' => isset($hasSnapshot['sheet']),
            ],
        ]);
    }

    /**
     * Reczne przypisanie kodu zrodlowego do kodu PIM.
     *
     * Kod zrodlowy moze wskazywac tylko na JEDEN kod PIM — jesli jest juz zajety,
     * mowimy przez ktory, zamiast po cichu przepinac. Rozdwojony stan byloby
     * potem widac dopiero w liczbach.
     */
    public function storeWarehouseMap(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_code' => ['required', 'string', Rule::exists('products', 'product_code')],
            'source' => ['required', 'string', Rule::in(array_keys(WarehouseCodeMap::SOURCES))],
            'source_code' => ['required', 'string', 'max:100'],
        ]);

        $data['source_code'] = trim($data['source_code']);

        $taken = WarehouseCodeMap::where('source', $data['source'])
            ->where('source_code', $data['source_code'])
            ->first();

        if ($taken !== null && $taken->product_code !== $data['product_code']) {
            return response()->json([
                'message' => "Kod {$data['source_code']} jest już przypisany do {$taken->product_code}.",
            ], 422);
        }

        WarehouseCodeMap::updateOrCreate(
            ['source' => $data['source'], 'source_code' => $data['source_code']],
            ['product_code' => $data['product_code'], 'manual' => true],
        );

        // Obszar bierzemy z frontu, bo to samo przypisanie da sie zrobic
        // z listy M3R i z Tabeli — w dzienniku ma byc widac, skad padlo.
        WarehouseLog::write(
            $request->input('area', 'm3r'),
            'map.store',
            sprintf(
                '%s (%s) → %s',
                $data['source_code'],
                WarehouseCodeMap::SOURCES[$data['source']] ?? $data['source'],
                $data['product_code'],
            ),
            [
                'source_code' => $data['source_code'],
                'product_code' => $data['product_code'],
                'meta' => ['zrodlo' => $data['source']],
            ],
        );

        return response()->json($this->mapPayload($data['product_code']));
    }

    /**
     * Zdjecie przypisania. Kod zrodlowy wraca do „Do zmapowania".
     */
    public function destroyWarehouseMap(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_code' => ['required', 'string'],
            'source' => ['required', 'string', Rule::in(array_keys(WarehouseCodeMap::SOURCES))],
            'source_code' => ['required', 'string'],
        ]);

        WarehouseCodeMap::where('source', $data['source'])
            ->where('source_code', $data['source_code'])
            ->delete();

        WarehouseLog::write(
            $request->input('area', 'm3r'),
            'map.destroy',
            sprintf(
                'odpięto %s (%s) od %s',
                $data['source_code'],
                WarehouseCodeMap::SOURCES[$data['source']] ?? $data['source'],
                $data['product_code'],
            ),
            [
                'source_code' => $data['source_code'],
                'product_code' => $data['product_code'],
                'meta' => ['zrodlo' => $data['source']],
            ],
        );

        return response()->json($this->mapPayload($data['product_code']));
    }

    /**
     * Aktualne przypisania jednego kodu PIM — tym grid odswieza wiersz po zapisie,
     * zamiast przeladowywac cala liste.
     */
    private function mapPayload(string $productCode): array
    {
        $manual = WarehouseCodeMap::where('product_code', $productCode)
            ->orderBy('source_code')
            ->get();

        // Wiersze stanu o kodzie identycznym z kodem PIM — te dopinaja sie same.
        // Pomijamy takie, ktore ktos przypial recznie (gdziekolwiek), bo reczne
        // przypisanie zawsze przebija automat.
        $upper = mb_strtoupper($productCode);
        $autoCandidates = collect();

        foreach (WarehouseSourceStock::where('source', 'gt')->whereRaw('UPPER(source_code) = ?', [$upper])->get() as $row) {
            $autoCandidates->push(['source' => 'gt', 'code' => $row->source_code]);
        }

        foreach (WarehouseSheetRow::where('sheet', WarehouseSheetRow::DEFAULT_SHEET)
            ->whereRaw('UPPER(product_code) = ?', [$upper])->get() as $row) {
            $autoCandidates->push(['source' => 'sheet', 'code' => $row->product_code]);
        }

        $claimed = WarehouseCodeMap::whereIn('source_code', $autoCandidates->pluck('code')->all())
            ->get()
            ->keyBy(fn ($row) => $row->source.'|'.mb_strtoupper($row->source_code));

        $payload = ['product_code' => $productCode];

        foreach (array_keys(WarehouseCodeMap::SOURCES) as $source) {
            $entries = $manual->where('source', $source)
                ->map(fn ($row) => ['code' => $row->source_code, 'auto' => false])
                ->values()
                ->all();

            foreach ($autoCandidates->where('source', $source) as $candidate) {
                if (! $claimed->has($source.'|'.mb_strtoupper($candidate['code']))) {
                    $entries[] = ['code' => $candidate['code'], 'auto' => true];
                }
            }

            $payload['map_'.$source] = $entries;
        }

        return $payload;
    }

    /**
     * Magazyn → Tabela: arkusz inwentury 1:1, w tym samym ukladzie kolumn,
     * w jakim prowadza go ludzie (kod, do szesciu par Miejsce/ilosc, uwagi).
     *
     * Dane wchodza komenda `warehouse:import-sheet`, nie zywym polaczeniem
     * z Google — arkusz jest wysylany recznie, wiec ekran pokazuje date
     * ostatniego importu zamiast udawac, ze widzi biezacy stan.
     *
     * `in_pim` liczymy tutaj, a nie w bazie: kod z arkusza bywa zapisany
     * inaczej niz `products.product_code` (spacje, wielkosc liter), a to,
     * czy ma pare, decyduje o tym, czy wiersz idzie do „Do zmapowania”.
     */
    public function warehouseTable(Request $request): Response
    {
        $sheet = WarehouseSheetRow::DEFAULT_SHEET;
        $rows = WarehouseSheetRow::where('sheet', $sheet)->orderBy('row_no')->get();

        // Regula dopasowania musi byc DOKLADNIE ta sama co na liscie M3R,
        // inaczej ten sam wiersz jest tam zmapowany, a tu nie. Stad ten sam
        // katalog kodow i to samo porownanie — wielkosc liter bez znaczenia,
        // reszta znakow (spacje!) juz nie.
        $byUpperCode = $this->codeCatalog()->keys()
            ->mapWithKeys(fn ($code) => [mb_strtoupper($code) => $code]);

        // Reczne przypisania wygrywaja z automatem: jesli ktos przypial kod
        // swiadomie, dopasowanie po nazwie nie ma prawa go przebic.
        $manual = WarehouseCodeMap::where('source', 'sheet')
            ->get(['product_code', 'source_code'])
            ->keyBy(fn ($row) => mb_strtoupper($row->source_code));

        // Rezerwacje sa OBOK ilosci, nie zamiast nich — stan mowi, ile lezy,
        // rezerwacja ile z tego jest obiecane.
        $reservations = WarehouseReservation::active()
            ->where('source', 'sheet')
            ->orderBy('id')
            ->get()
            ->groupBy('source_code');

        return Inertia::render('Production/WarehouseTable', [
            'sheet' => $sheet,
            'importedAt' => $rows->max('updated_at')?->format('Y-m-d H:i'),
            'rows' => $rows->map(function (WarehouseSheetRow $row) use ($manual, $byUpperCode, $reservations) {
                $upper = mb_strtoupper($row->product_code);
                $manualTarget = $manual[$upper]->product_code ?? null;
                $autoTarget = $byUpperCode[$upper] ?? null;
                $reserved = $reservations[$row->product_code] ?? collect();

                return [
                    'id' => $row->id,
                    'row_no' => $row->row_no,
                    'product_code' => $row->product_code,
                    'place_1' => $row->place_1, 'qty_1' => $row->qty_1,
                    'place_2' => $row->place_2, 'qty_2' => $row->qty_2,
                    'place_3' => $row->place_3, 'qty_3' => $row->qty_3,
                    'place_4' => $row->place_4, 'qty_4' => $row->qty_4,
                    'place_5' => $row->place_5, 'qty_5' => $row->qty_5,
                    'place_6' => $row->place_6, 'qty_6' => $row->qty_6,
                    'quantity_total' => $row->quantity_total,
                    'steel_team' => $row->steel_team,
                    'uwagi' => $row->uwagi,
                    'wymiar' => $row->wymiar,
                    'waga' => $row->waga,
                    // Kod PIM, na ktory ten wiersz arkusza faktycznie wchodzi —
                    // recznie wskazany albo dopasowany po kodzie. NULL znaczy
                    // „nigdzie nie wchodzi", czyli kubelek „Do zmapowania".
                    'mapped_to' => $manualTarget ?? $autoTarget,
                    // Rozroznienie, ktore widac na ekranie: automatu nie da sie
                    // odpiac, bo nie ma czego — nie ma wpisu w bazie.
                    'mapped_auto' => $manualTarget === null && $autoTarget !== null,
                    // Do czego wiersz wroci po odpieciu recznego przypisania —
                    // bez tego ekran po „Odepnij" pokazywalby „do zmapowania"
                    // tam, gdzie automat i tak zaraz dopasuje kod.
                    'auto_to' => $autoTarget,
                    'reservations' => $reserved->map(fn (WarehouseReservation $item) => [
                        'id' => $item->id,
                        'user_name' => $item->user_name,
                        'quantity' => $item->quantity,
                        'note' => $item->note,
                        'label' => $item->label(),
                    ])->values()->all(),
                    'reserved' => (int) $reserved->sum('quantity'),
                ];
            })->values(),
            'source' => 'sheet',
            'editable' => self::SHEET_EDITABLE,
        ]);
    }

    /**
     * Reczna poprawka komorek arkusza wprost z gridu.
     *
     * Zmiany ida PACZKA, bo RevoGrid pozwala wkleic zakres — jedno wklejenie
     * to kilkadziesiat komorek, a nie kilkadziesiat zapytan.
     *
     * `quantity_total` liczy serwer i oddaje z powrotem: suma szesciu pol nie
     * moze zalezec od tego, czy przegladarka ja doliczyla po swojemu.
     *
     * UWAGA: to sa poprawki NA WIERZCHU importu. Kolejny `warehouse:import-sheet`
     * podmienia cala zakladke, wiec reczne zmiany maja sens jako korekta miedzy
     * inwenturami, a nie jako druga, rownolegla ewidencja.
     */
    public function updateWarehouseSheetCells(Request $request): JsonResponse
    {
        $data = $request->validate([
            'changes' => ['required', 'array', 'min:1', 'max:500'],
            'changes.*.id' => ['required', 'integer'],
            'changes.*.field' => ['required', 'string', Rule::in(self::SHEET_EDITABLE)],
            'changes.*.value' => ['nullable'],
        ]);

        // Liczby sprawdzamy PRZED zapisem czegokolwiek — jedna literowka w
        // ilosci nie moze zostawic polowy wklejonego zakresu zapisanej.
        foreach ($data['changes'] as $change) {
            if (! str_starts_with($change['field'], 'qty_')) {
                continue;
            }

            $raw = $this->sheetRawNumber($change['value']);

            if ($raw !== null && ! is_numeric($raw)) {
                return response()->json([
                    'message' => "„{$change['value']}” to nie liczba — ilość zostaje bez zmian.",
                ], 422);
            }
        }

        $rows = WarehouseSheetRow::whereIn('id', array_column($data['changes'], 'id'))->get()->keyBy('id');
        $entries = [];

        foreach ($data['changes'] as $change) {
            $row = $rows[$change['id']] ?? null;

            if ($row === null) {
                continue;
            }

            $field = $change['field'];
            $before = $row->{$field};
            $after = $this->sheetCellValue($field, $change['value']);

            $row->{$field} = $after;

            // Log powstaje z wartosci PRZED przypisaniem — po zapisie nie ma juz
            // z czego odtworzyc, co bylo w komorce.
            $entries[] = [
                'code' => $row->product_code,
                'field' => $field,
                'before' => $before,
                'after' => $after,
            ];
        }

        DB::transaction(function () use ($rows) {
            foreach ($rows as $row) {
                $total = 0;

                foreach (range(1, 6) as $i) {
                    $total += (int) $row->{"qty_$i"};
                }

                $row->quantity_total = $total;
                $row->save();
            }
        });

        foreach ($entries as $entry) {
            $label = self::SHEET_FIELD_LABELS[$entry['field']] ?? $entry['field'];

            WarehouseLog::write('tabela', 'cell.update', sprintf(
                '%s — %s: %s → %s',
                $entry['code'],
                $label,
                $this->logValue($entry['before']),
                $this->logValue($entry['after']),
            ), [
                'source_code' => $entry['code'],
                'meta' => ['pole' => $entry['field'], 'przed' => $entry['before'], 'po' => $entry['after']],
            ]);
        }

        // Oddajemy przeliczone sumy — grid podmienia kolumne „Razem" bez
        // przeladowania calej listy.
        return response()->json([
            'rows' => $rows->map(fn (WarehouseSheetRow $row) => [
                'id' => $row->id,
                'quantity_total' => $row->quantity_total,
            ])->values(),
        ]);
    }

    /** Surowa liczba z komorki: pusto to NULL, przecinek dziesietny na kropke. */
    private function sheetRawNumber(mixed $value): ?string
    {
        $value = is_string($value) ? trim(str_replace(',', '.', $value)) : $value;

        return ($value === null || $value === '') ? null : (string) $value;
    }

    /**
     * Wartosc do zapisu. Pusta komorka to NULL, a nie pusty string ani zero —
     * na tym ekranie „nikt nie liczyl" i „policzone, nie ma" to dwie rozne rzeczy.
     */
    private function sheetCellValue(string $field, mixed $value): string|int|null
    {
        if (str_starts_with($field, 'qty_')) {
            $raw = $this->sheetRawNumber($value);

            return $raw === null ? null : (int) round((float) $raw);
        }

        $value = is_string($value) ? trim($value) : $value;

        return ($value === null || $value === '') ? null : mb_substr((string) $value, 0, 255);
    }

    /** Wartosc do zdania w logu. Puste pole musi byc widoczne jako puste. */
    private function logValue(mixed $value): string
    {
        return ($value === null || $value === '') ? '(puste)' : (string) $value;
    }

    /**
     * Rezerwacja pozycji: ktos odklada X sztuk dla siebie.
     *
     * Rezerwacja NIE rusza stanu — stan mowi, ile lezy na polce, rezerwacja ile
     * z tego jest obiecane. Gdyby odejmowac ja od stanu, nikt by juz nie odroznil
     * „nie ma towaru" od „jest, ale ktos go trzyma".
     */
    public function storeWarehouseReservation(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source_code' => ['required', 'string', 'max:100'],
            'quantity' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:255'],
            'area' => ['nullable', 'string', Rule::in(array_keys(WarehouseLog::AREAS))],
        ]);

        $user = $request->user();
        $sourceCode = trim($data['source_code']);

        $reservation = WarehouseReservation::create([
            'source' => 'sheet',
            'source_code' => $sourceCode,
            'product_code' => WarehouseCodeMap::where('source', 'sheet')
                ->where('source_code', $sourceCode)
                ->value('product_code'),
            'quantity' => $data['quantity'],
            'user_id' => $user?->id,
            'user_name' => WarehouseLog::actorName($user),
            'note' => $data['note'] ?? null,
        ]);

        WarehouseLog::write(
            $data['area'] ?? 'tabela',
            'reservation.create',
            "{$sourceCode} — rezerwacja {$reservation->quantity} szt. dla {$reservation->user_name}",
            [
                'source_code' => $sourceCode,
                'product_code' => $reservation->product_code,
                'meta' => ['ilosc' => $reservation->quantity, 'uwaga' => $reservation->note],
            ],
        );

        return response()->json($this->reservationPayload($sourceCode));
    }

    /**
     * Zwolnienie rezerwacji. Wiersz zostaje w bazie ze znacznikiem — znika
     * z ekranu, ale nie z historii; inaczej przepada odpowiedz na pytanie,
     * kto trzymal towar przez tydzien.
     */
    public function releaseWarehouseReservation(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['required', 'integer'],
            'area' => ['nullable', 'string', Rule::in(array_keys(WarehouseLog::AREAS))],
        ]);

        $reservation = WarehouseReservation::active()->findOrFail($data['id']);
        $user = $request->user();

        $reservation->forceFill([
            'released_at' => now(),
            'released_by' => WarehouseLog::actorName($user),
        ])->save();

        WarehouseLog::write(
            $data['area'] ?? 'tabela',
            'reservation.release',
            "{$reservation->source_code} — zwolniono rezerwację {$reservation->quantity} szt. ({$reservation->user_name})",
            [
                'source_code' => $reservation->source_code,
                'product_code' => $reservation->product_code,
                'meta' => ['ilosc' => $reservation->quantity],
            ],
        );

        return response()->json($this->reservationPayload($reservation->source_code));
    }

    /** Zywe rezerwacje jednego kodu — tym ekran odswieza wiersz po zmianie. */
    private function reservationPayload(string $sourceCode): array
    {
        return [
            'source_code' => $sourceCode,
            'reservations' => WarehouseReservation::active()
                ->where('source', 'sheet')
                ->where('source_code', $sourceCode)
                ->orderBy('id')
                ->get()
                ->map(fn (WarehouseReservation $row) => [
                    'id' => $row->id,
                    'user_name' => $row->user_name,
                    'quantity' => $row->quantity,
                    'note' => $row->note,
                    'label' => $row->label(),
                ])->values()->all(),
        ];
    }

    /**
     * Magazyn → Ustawienia. Na razie jedna zakladka: polaczenie z ARGO Bridge.
     *
     * Token idzie na ekran jawnie, bo trzeba go wkleic po drugiej stronie —
     * w Bridge na maszynie z Subiektem. Ekran siedzi za uprawnieniem
     * `crafter.module.production`, wiec nie oglada go kto popadnie.
     */
    public function warehouseSettings(Request $request): Response
    {
        $bridge = WarehouseBridge::current();

        return Inertia::render('Production/WarehouseSettings', [
            'bridge' => [
                'enabled' => $bridge->enabled,
                'warehouse_symbol' => $bridge->warehouse_symbol,
                'token' => $bridge->api_token,
                'status' => $bridge->status(),
                // Czas serwera to UTC — na ekran ida obie formy, zeby nikt nie
                // musial przeliczac godziny w glowie.
                'last_seen_at' => $bridge->last_seen_at?->format('Y-m-d H:i'),
                'last_seen_human' => $bridge->last_seen_at?->diffForHumans(),
                'last_sync_at' => $bridge->last_sync_at?->format('Y-m-d H:i'),
                'last_codes' => $bridge->last_codes,
                'version' => $bridge->bridge_version,
                'silent_after_minutes' => WarehouseBridge::SILENT_AFTER_MINUTES,
                // Adres, pod ktory Bridge sie melduje. Z `route()`, a nie z reki —
                // zeby na kazdym srodowisku pokazywal wlasny host.
                'ping_url' => route('api.argo-bridge.ping'),
            ],
        ]);
    }

    /**
     * Zapis ustawien Bridge'a. Tokenu tu nie ma — ten zmienia sie wylacznie
     * przez wygenerowanie nowego, zeby nie dalo sie go nadpisac przypadkiem
     * ani wkleic z zewnatrz.
     */
    public function updateWarehouseBridge(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'warehouse_symbol' => ['nullable', 'string', 'max:100'],
        ]);

        $bridge = WarehouseBridge::current();
        $before = ['enabled' => $bridge->enabled, 'warehouse_symbol' => $bridge->warehouse_symbol];

        $bridge->fill($data)->save();

        WarehouseLog::write('ustawienia', 'bridge.settings', sprintf(
            'Połączenie z ARGO Bridge: %s, magazyn „%s”',
            $bridge->enabled ? 'włączone' : 'wyłączone',
            $bridge->warehouse_symbol ?? '—',
        ), ['meta' => ['przed' => $before, 'po' => $data]]);

        return back();
    }

    /**
     * Nowy token dla Bridge'a. Wygenerowanie UNIEWAZNIA stary — Bridge z poprzednim
     * tokenem dostanie 401, dopoki nie wklei sie nowego po tamtej stronie.
     */
    public function regenerateWarehouseBridgeToken(Request $request): RedirectResponse
    {
        WarehouseBridge::current()
            ->forceFill(['api_token' => bin2hex(random_bytes(32))])
            ->save();

        // Tokenu do logu NIE wpisujemy — dziennik oglada wiecej osob niz
        // ekran Ustawien, a token jest haslem do wejscia po naszej stronie.
        WarehouseLog::write('ustawienia', 'bridge.token', 'Wygenerowano nowy token dla ARGO Bridge (poprzedni unieważniony)');

        return back();
    }

    /**
     * Magazyn → Logi: dziennik pobran z ARGO Bridge i z arkusza. Na razie pusty.
     */
    public function warehouseLogs(Request $request): Response
    {
        // Ostatnie 2000 wpisow — dziennik ma odpowiadac na „co sie stalo dzis
        // i wczoraj", a nie byc archiwum przewijanym w nieskonczonosc. Filtry
        // dziala na tym samym zestawie po stronie przegladarki, wiec przelaczenie
        // zakladki nie kosztuje round-tripu.
        $logs = WarehouseLog::query()
            ->orderByDesc('id')
            ->limit(2000)
            ->get();

        return Inertia::render('Production/WarehouseLogs', [
            'areas' => WarehouseLog::AREAS,
            'logs' => $logs->map(fn (WarehouseLog $log) => [
                'id' => $log->id,
                'at' => $log->created_at?->format('Y-m-d H:i'),
                'user' => $log->user_name ?? 'system',
                'area' => $log->area,
                'area_label' => WarehouseLog::AREAS[$log->area] ?? $log->area,
                'action' => $log->action,
                'source_code' => $log->source_code,
                'product_code' => $log->product_code,
                'description' => $log->description,
            ])->values(),
            'users' => $logs->pluck('user_name')->filter()->unique()->sort()->values(),
        ]);
    }

    /**
     * Przestawia jeden znacznik na jednym kodzie. Wolane axiosem z gridu.
     */
    public function setFlag(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_code' => ['required', 'string', Rule::exists('products', 'product_code')],
            'flag' => ['required', 'string', Rule::in(array_keys(self::FLAGS))],
            'value' => ['required', 'boolean'],
        ]);

        $item = ProductionItem::updateOrCreate(
            ['product_code' => $data['product_code']],
            [self::FLAGS[$data['flag']] => $data['value']],
        );

        return response()->json([
            'product_code' => $data['product_code'],
            'flags' => collect(self::FLAGS)->map(fn ($column) => (bool) $item->{$column}),
        ]);
    }
}
