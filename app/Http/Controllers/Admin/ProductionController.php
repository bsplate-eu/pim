<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Attribute;
use App\Models\Product;
use App\Models\ProductionItem;
use App\Models\ProductionStage;
use App\Models\WarehouseBridge;
use App\Models\WarehouseCodeMap;
use App\Models\WarehouseSheetRow;
use App\Services\Production\CodeGrouper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
        // Mapa kodow zrodlowych, zwinieta do listy per kod PIM i per zrodlo.
        // Jeden kod PIM moze miec kilka kodow zrodlowych — stad tablice, nie stringi.
        $maps = WarehouseCodeMap::orderBy('source_code')->get()
            ->groupBy('product_code')
            ->map(fn ($group) => $group->groupBy('source')
                ->map(fn ($rows) => $rows->pluck('source_code')->values()->all()));

        $rows = $this->codeCatalog()
            ->map(function (array $row) use ($maps) {
                $forCode = $maps[$row['product_code']] ?? collect();

                foreach (array_keys(WarehouseCodeMap::SOURCES) as $source) {
                    $row['map_'.$source] = $forCode[$source] ?? [];
                }

                return $row;
            })
            ->values();

        return Inertia::render('Production/Warehouse', [
            'rows' => $rows,
            'unmapped' => [],
            'sources' => WarehouseCodeMap::SOURCES,
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

        return response()->json($this->mapPayload($data['product_code']));
    }

    /**
     * Aktualne przypisania jednego kodu PIM — tym grid odswieza wiersz po zapisie,
     * zamiast przeladowywac cala liste.
     */
    private function mapPayload(string $productCode): array
    {
        $rows = WarehouseCodeMap::where('product_code', $productCode)
            ->orderBy('source_code')
            ->get();

        $payload = ['product_code' => $productCode];

        foreach (array_keys(WarehouseCodeMap::SOURCES) as $source) {
            $payload['map_'.$source] = $rows->where('source', $source)
                ->pluck('source_code')
                ->values()
                ->all();
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

        // Porownanie po znormalizowanym kodzie zdejmuje rozjazdy typu
        // „25.159 ALU" vs „25.159ALU", ktore nie sa realnym brakiem produktu.
        $normalize = fn (?string $code) => strtoupper(str_replace(' ', '', trim((string) $code)));
        $known = Product::query()->pluck('product_code')
            ->filter()
            ->mapWithKeys(fn ($code) => [$normalize($code) => true]);

        return Inertia::render('Production/WarehouseTable', [
            'sheet' => $sheet,
            'importedAt' => $rows->max('updated_at')?->format('Y-m-d H:i'),
            'rows' => $rows->map(fn (WarehouseSheetRow $row) => [
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
                'in_pim' => $known->has($normalize($row->product_code)),
            ])->values(),
        ]);
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

        WarehouseBridge::current()->fill($data)->save();

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

        return back();
    }

    /**
     * Magazyn → Logi: dziennik pobran z ARGO Bridge i z arkusza. Na razie pusty.
     */
    public function warehouseLogs(Request $request): Response
    {
        return Inertia::render('Production/WarehouseLogs');
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
