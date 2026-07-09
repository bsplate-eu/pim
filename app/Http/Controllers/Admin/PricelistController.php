<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Pricelist\IndexPricelistRequest;
use App\Http\Requests\Admin\Pricelist\CreatePricelistRequest;
use App\Http\Requests\Admin\Pricelist\StorePricelistRequest;
use App\Http\Requests\Admin\Pricelist\EditPricelistRequest;
use App\Http\Requests\Admin\Pricelist\UpdatePricelistRequest;
use App\Http\Requests\Admin\Pricelist\DestroyPricelistRequest;
use App\Http\Requests\Admin\Pricelist\BulkDestroyPricelistRequest;
use App\Models\Pricelist;
use App\Models\PricelistProduct;
use App\Models\Product;
use App\Models\Source;
use App\Queries\Filters\FuzzyFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PricelistController extends Controller
{
    /**
     * Slug cennika bazowego ("Cennink bazowy - JSON", EUR) — zrodlo cen zakupu.
     */
    private const BASE_PRICELIST_SLUG = 'sumpguard';

    /**
     * Display a listing of the resource.
     */
    public function index(IndexPricelistRequest $request): Response|JsonResponse
    {
        $pricelistsQuery = QueryBuilder::for(Pricelist::class)
            ->allowedFilters([
                AllowedFilter::custom('search', new FuzzyFilter(
                    'id', 'name', 'currency'
                )),
            ])
            ->defaultSort('id')
            ->allowedSorts('id', 'name', 'currency');

        if ($request->wantsJson() && $request->get('bulk_select_all')) {
            return response()->json($pricelistsQuery->select(['id'])->pluck('id'));
        }

        $pricelists = $pricelistsQuery
            ->select('id', 'name', 'currency')
            ->paginate($request->get('per_page'))->withQueryString();

        Session::put('pricelists_url', $request->fullUrl());

        return Inertia::render('Pricelist/Index', [
            'pricelists' => $pricelists,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(CreatePricelistRequest $request): Response
    {
        return Inertia::render('Pricelist/Create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StorePricelistRequest $request): RedirectResponse
    {
        $pricelist = Pricelist::create(array_merge($request->validated(), ['slug' => Str::slug($request->name)]));

        return redirect()->route('crafter.pricelists.edit', $pricelist)->with(['message' => ___('crafter', 'Operation successful')]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(EditPricelistRequest $request, Pricelist $pricelist): Response
    {
        $prices = PricelistProduct::where('pricelist_id', $pricelist->id)->pluck('price', 'product_id');
        // Cena netto "automatyczna" — wynik Operacji masowych (oddzielna od ceny wlasciwej).
        $autoPrices = PricelistProduct::where('pricelist_id', $pricelist->id)->pluck('auto_price', 'product_id');
        // Cena reczna — twardy override; gdy > 0 jest cena eksportowa.
        $manualPrices = PricelistProduct::where('pricelist_id', $pricelist->id)->pluck('manual_price', 'product_id');

        // Ceny zakupu (EUR) z cennika bazowego — po product_id.
        $baseId = Pricelist::where('slug', self::BASE_PRICELIST_SLUG)->value('id');
        $purchase = $baseId
            ? PricelistProduct::where('pricelist_id', $baseId)->pluck('price', 'product_id')
            : collect();

        $rows = Product::select('id', 'product_code', 'name', 'source_id')
            ->orderBy('product_code')
            ->get()
            ->map(fn ($product) => [
                'product_id' => $product->id,
                'product_code' => $product->product_code,
                'name' => $product->name,
                'price' => (float) ($prices[$product->id] ?? 0),
                'auto_price' => (float) ($autoPrices[$product->id] ?? 0),
                'manual_price' => (float) ($manualPrices[$product->id] ?? 0),
                'purchase_price' => (float) ($purchase[$product->id] ?? 0),
                'source_id' => $product->source_id,
            ]);

        return Inertia::render('Pricelist/Edit', [
            'pricelist' => $pricelist,
            'rows' => $rows,
            'sources' => Source::orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdatePricelistRequest $request, Pricelist $pricelist): RedirectResponse
    {
        $pricelist->update($request->validated());

        $rows = collect($request->input('rows', []))
            ->filter(fn ($row) => !empty($row['product_id']))
            ->map(fn ($row) => [
                'pricelist_id' => $pricelist->id,
                'product_id' => (int) $row['product_id'],
                'price' => $this->normalizePrice((string) ($row['price'] ?? '')) ?? 0,
                'auto_price' => $this->normalizePrice((string) ($row['auto_price'] ?? '')) ?? 0,
                'manual_price' => $this->normalizePrice((string) ($row['manual_price'] ?? '')) ?? 0,
            ])
            ->values()
            ->toArray();

        if (!empty($rows)) {
            PricelistProduct::upsert($rows, ['pricelist_id', 'product_id'], ['price', 'auto_price', 'manual_price']);
        }

        if (session('pricelists_url')) {
            return redirect(session('pricelists_url'))->with(['message' => ___('crafter', 'Operation successful')]);
        }

        return redirect()->route('crafter.pricelists.index')->with(['message' => ___('crafter', 'Operation successful')]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(DestroyPricelistRequest $request, Pricelist $pricelist): RedirectResponse
    {
        $pricelist->delete();

        return redirect()->back()->with(['message' => ___('crafter', 'Operation successful')]);
    }

    /**
     * Bulk destroy resource.
     */
    public function bulkDestroy(BulkDestroyPricelistRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request) {
            Pricelist::whereIn('id', $request->validated()['ids'])->each(function ($pricelist) {
                $pricelist->delete();
            });
        });

        return redirect()->back()->with(['message' => ___('crafter', 'Operation successful')]);
    }

    /**
     * Klonuje cennik: tworzy nowy rekord z sufiksem "(kopia)" i kopiuje wszystkie wiersze
     * pricelist_product. Tworzy unikalny name i slug (jesli kolizja → kopia 2, 3, ...).
     */
    public function clone(Pricelist $pricelist): RedirectResponse
    {
        $newName = $this->uniqueName($pricelist->name . ' (kopia)');
        $newSlug = $this->uniqueSlug(Str::slug($newName));

        $clone = Pricelist::create([
            'name' => $newName,
            'slug' => $newSlug,
            'currency' => $pricelist->currency,
        ]);

        $now = now();
        PricelistProduct::where('pricelist_id', $pricelist->id)
            ->orderBy('id')
            ->chunkById(1000, function ($rows) use ($clone, $now) {
                $batch = $rows->map(fn ($r) => [
                    'pricelist_id' => $clone->id,
                    'product_id' => $r->product_id,
                    'price' => $r->price,
                    'auto_price' => $r->auto_price,
                    'manual_price' => $r->manual_price,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();
                PricelistProduct::insert($batch);
            });

        return redirect()->route('crafter.pricelists.edit', $clone)
            ->with(['message' => "Utworzono kopię cennika \"{$clone->name}\""]);
    }

    private function uniqueName(string $base): string
    {
        $name = $base;
        $i = 2;
        while (Pricelist::where('name', $name)->exists()) {
            $name = $base . ' ' . $i++;
        }
        return $name;
    }

    private function uniqueSlug(string $base): string
    {
        $slug = $base;
        $i = 2;
        while (Pricelist::where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i++;
        }
        return $slug;
    }

    /**
     * Aktualizacja cennika z pliku CSV w formacie eksportu (kolumny "id" = product.id i "price").
     * Pozostale kolumny CSV (product_code, name) sa ignorowane przy imporcie.
     */
    public function importCsv(\Illuminate\Http\Request $request, Pricelist $pricelist): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:10240']]);

        [$header, $rows] = $this->readCsv($request->file('file')->getRealPath());

        // Klucz produktu: 'id' (product_id) LUB 'external_id' (mapowany do product_id).
        $idIdx     = array_search('id', $header, true);
        $extIdx    = array_search('external_id', $header, true);
        $priceIdx  = array_search('price', $header, true);
        $manualIdx = array_search('manual_price', $header, true);

        if ($idIdx === false && $extIdx === false) {
            return back()->withErrors(['file' => 'CSV musi zawierac kolumne "id" lub "external_id"']);
        }
        if ($priceIdx === false && $manualIdx === false) {
            return back()->withErrors(['file' => 'CSV musi zawierac kolumne "price" lub "manual_price"']);
        }

        // Mapa external_id => product_id (gdy plik używa external_id).
        $extToId = [];
        if ($extIdx !== false) {
            $exts = array_filter(array_map(fn ($r) => trim((string) ($r[$extIdx] ?? '')), $rows));
            $extToId = \App\Models\Product::whereIn('external_id', array_unique($exts))
                ->pluck('id', 'external_id')->all();
        }

        $data = [];
        foreach ($rows as $r) {
            $productId = $idIdx !== false
                ? (int) ($r[$idIdx] ?? 0)
                : (int) ($extToId[trim((string) ($r[$extIdx] ?? ''))] ?? 0);
            if ($productId <= 0) {
                continue;
            }
            $row = ['pricelist_id' => $pricelist->id, 'product_id' => $productId];
            if ($priceIdx !== false)  $row['price']        = $this->normalizePrice((string) ($r[$priceIdx] ?? '')) ?? 0;
            if ($manualIdx !== false) $row['manual_price'] = $this->normalizePrice((string) ($r[$manualIdx] ?? '')) ?? 0;
            // `price` jest NOT NULL — dodaj 0 dla ewentualnego INSERT (nowy wiersz w cenniku).
            // Istniejących nie tknie: updateCols aktualizuje tylko kolumny z pliku.
            $row['price'] ??= 0;
            $data[] = $row;
        }

        // Aktualizuj tylko te kolumny cenowe, które faktycznie były w pliku.
        $updateCols = [];
        if ($priceIdx !== false)  $updateCols[] = 'price';
        if ($manualIdx !== false) $updateCols[] = 'manual_price';

        $imported = 0;
        foreach (array_chunk($data, 1000) as $chunk) {
            PricelistProduct::upsert($chunk, ['pricelist_id', 'product_id'], $updateCols);
            $imported += count($chunk);
        }

        return back()->with(['message' => "Zaktualizowano {$imported} wierszy z CSV"]);
    }

    /**
     * Wczytuje CSV: zdejmuje BOM, wykrywa separator (`,` lub `;`), zwraca [header, rows].
     */
    private function readCsv(string $path): array
    {
        $content = file_get_contents($path);
        if ($content === false) {
            return [[], []];
        }

        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        $firstNl = strpos($content, "\n");
        $firstLine = $firstNl === false ? $content : substr($content, 0, $firstNl);
        $delim = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';

        $lines = preg_split("/\r\n|\n|\r/", $content) ?: [];
        $rows = [];
        $header = [];
        foreach ($lines as $i => $line) {
            if ($line === '') {
                continue;
            }
            $parsed = str_getcsv($line, $delim);
            if (empty($header)) {
                $header = array_map('trim', $parsed);
                continue;
            }
            $rows[] = $parsed;
        }

        return [$header, $rows];
    }

    /**
     * Eksport CSV w formacie identycznym jak stary arkusz Google (id, product_code, name, price).
     */
    public function exportCsv(Pricelist $pricelist): StreamedResponse
    {
        // Cena eksportowa: reczna (manual_price) gdy > 0, inaczej wlasciwa (price).
        $prices = PricelistProduct::exportPriceMap($pricelist->id);
        $filename = 'cennik-' . Str::slug($pricelist->name) . '-' . date('Ymd') . '.csv';

        return response()->streamDownload(function () use ($prices) {
            $out = fopen('php://output', 'w');
            // BOM utf-8 zeby Excel poprawnie czytal polskie znaki
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['id', 'product_code', 'name', 'price']);

            Product::select('id', 'product_code', 'name')
                ->orderBy('product_code')
                ->chunk(500, function ($products) use ($prices, $out) {
                    foreach ($products as $p) {
                        fputcsv($out, [$p->id, $p->product_code, $p->name, (float) ($prices[$p->id] ?? 0)]);
                    }
                });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Normalizuje cene wpisana w gridzie (PL/EU separatory) do formatu decimal.
     */
    private function normalizePrice(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $value = preg_replace('/[^\d.,-]/u', '', $value);

        if (str_contains($value, ',') && str_contains($value, '.')) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } else {
            $value = str_replace(',', '.', $value);
        }

        return number_format((float) $value, 2, '.', '');
    }
}
