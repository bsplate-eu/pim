<?php

namespace App\Http\Controllers\Admin;

use App\Exports\Admin\SellyIntegrationProductsExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IntegrationProduct\IndexIntegrationProductRequest;
use App\Http\Requests\Admin\IntegrationProduct\CreateIntegrationProductRequest;
use App\Http\Requests\Admin\IntegrationProduct\StoreIntegrationProductRequest;
use App\Http\Requests\Admin\IntegrationProduct\EditIntegrationProductRequest;
use App\Http\Requests\Admin\IntegrationProduct\UpdateIntegrationProductRequest;
use App\Http\Requests\Admin\IntegrationProduct\DestroyIntegrationProductRequest;
use App\Http\Requests\Admin\IntegrationProduct\BulkDestroyIntegrationProductRequest;
use App\Models\IntegrationProduct;
use App\Models\Integration;
use App\Models\PricelistProduct;
use App\Queries\Filters\FuzzyFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\Admin\PrestashopIntegrationProductsExport;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IntegrationProductController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(IndexIntegrationProductRequest $request, Integration $integration): Response
    {
        $integration->addAllEnabledProducts();

        $overrideKeys = ['name', 'ean', 'enabled'];

        $items = IntegrationProduct::with('product', 'integrationSource.template')
            ->where('integration_id', $integration->id)
            ->get()
            ->filter(fn (IntegrationProduct $item) => $item->product);

        // Cena zaciagana z cennika danego zrodla (pricelist_product.price) — bulk, bez N+1.
        $pricelistIds = $items->map(fn (IntegrationProduct $i) => $i->integrationSource?->pricelist_id)->filter()->unique();
        $productIds = $items->pluck('product_id')->unique();
        $priceMap = ($pricelistIds->isEmpty() || $productIds->isEmpty())
            ? collect()
            : PricelistProduct::whereIn('pricelist_id', $pricelistIds)
                ->whereIn('product_id', $productIds)
                ->selectRaw('pricelist_id, product_id, ' . PricelistProduct::EXPORT_PRICE_SQL . ' as price')
                ->get()
                ->keyBy(fn ($pp) => $pp->pricelist_id . ':' . $pp->product_id);

        $rows = $items
            ->map(function (IntegrationProduct $item) use ($overrideKeys, $priceMap) {
                app()->setLocale($item->integrationSource?->template?->locale ?? 'pl');

                $product = $item->product;
                $overrides = $item->overrides ?? [];

                $row = [
                    'product_id' => $product->id,
                    'external_id' => $product->external_id,
                    'product_code' => $product->product_code,
                    'price' => $priceMap->get($item->integrationSource?->pricelist_id . ':' . $product->id)?->price,
                ];

                foreach ($overrideKeys as $key) {
                    $row[$key] = $this->cellValue($product->{$key} ?? null);
                    $row["override_$key"] = $this->cellValue($overrides[$key] ?? null);
                }

                return $row;
            })
            ->values();

        return Inertia::render('IntegrationProduct/Edit', [
            'integration' => $integration,
            'rows' => $rows,
        ]);
    }

    private function cellValue($value)
    {
        return is_bool($value) ? (int) $value : $value;
    }

    private function getFormData(array $data = []): array
    {
        return array_merge([
            'integrationProduct' => new IntegrationProduct(),
        ], $data);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(CreateIntegrationProductRequest $request, Integration $integration): Response
    {
        return Inertia::render('IntegrationProduct/Create', $this->getFormData(['integration' => $integration]));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreIntegrationProductRequest $request, Integration $integration): RedirectResponse
    {
        $integrationProduct = IntegrationProduct::create($request->validated());

        return redirect()->route('crafter.integration-products.index')->with(['message' => ___('crafter', 'Operation successful')]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(EditIntegrationProductRequest $request, Integration $integration, IntegrationProduct $integrationProduct): Response
    {
        $integrationProduct->load('media', 'integration.template');
        $product = $integrationProduct->getOverridedProduct();
        $product->title = $integration->template->description;
        $product->description = $integration->template->description;

        return Inertia::render('IntegrationProduct/Edit', $this->getFormData([
            'integration' => $integration,
            'locale' => $integration->template->locale,
            'integrationProduct' => $integrationProduct,
            'product' => $product,
        ]));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Integration $integration): RedirectResponse
    {
        $integration->generateApiData();

        $overrideKeys = ['name', 'ean', 'enabled'];

        $data = collect($request->input('rows', []))
            ->filter(fn ($row) => !empty($row['product_id']))
            ->map(function ($row) use ($integration, $overrideKeys) {
                $overrides = [];
                foreach ($overrideKeys as $key) {
                    $value = $row["override_$key"] ?? null;
                    if (!empty($value) || $value == 0) {
                        $overrides[$key] = $value;
                    }
                }

                return [
                    'integration_id' => $integration->id,
                    'product_id' => (int) $row['product_id'],
                    'overrides' => json_encode($overrides),
                ];
            })
            ->values()
            ->toArray();

        if (!empty($data)) {
            IntegrationProduct::upsert($data, ['integration_id', 'product_id'], ['overrides']);
        }

        return redirect()->route('crafter.integrations.index')->with(['message' => ___('crafter', 'Operation successful')]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(DestroyIntegrationProductRequest $request, Integration $integration, IntegrationProduct $integrationProduct): RedirectResponse
    {
        $integrationProduct->delete();

        return redirect()->back()->with(['message' => ___('crafter', 'Operation successful')]);
    }

    /**
     * Bulk destroy resource.
     */
    public function bulkDestroy(BulkDestroyIntegrationProductRequest $request, Integration $integration): RedirectResponse
    {
        // Mass delete of resource
        DB::transaction(function () use ($request) {
            collect($request->validated()['ids'])
                ->chunk(1000)
                ->each(function ($bulkChunk) {
                    IntegrationProduct::whereIn('id', $bulkChunk)->delete();
                });
        });

        // Individual delete of resource items
        //        DB::transaction(function () use ($request) {
        //            collect($request->validated()['ids'])->each(function ($id) {
        //                IntegrationProduct::find($id)->delete();
        //            });
        //        });

        return redirect()->back()->with(['message' => ___('crafter', 'Operation successful')]);
    }

    /**
     * Aktualizacja nadpisan integracji z CSV w formacie eksportu.
     * Aktualizujemy TYLKO produkty, ktore juz istnieja w integration_products dla tej integracji
     * (CSV z lewymi id nie wstrzykuje sierot bez integration_source_id).
     */
    public function importCsv(Request $request, Integration $integration): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:10240']]);

        [$header, $rows] = $this->readCsv($request->file('file')->getRealPath());

        $idIdx = array_search('id', $header, true);
        if ($idIdx === false) {
            return back()->withErrors(['file' => 'CSV musi zawierac kolumne "id"']);
        }

        // mapa: indeks kolumny -> klucz override (np. 'name','ean','enabled')
        $overrideCols = [];
        foreach ($header as $i => $col) {
            if (is_string($col) && str_starts_with($col, 'overrides_')) {
                $overrideCols[$i] = substr($col, 10);
            }
        }
        if (empty($overrideCols)) {
            return back()->withErrors(['file' => 'CSV nie zawiera kolumn "overrides_*"']);
        }

        // tylko produkty juz przypiete do tej integracji
        $existing = IntegrationProduct::where('integration_id', $integration->id)
            ->pluck('product_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();

        $data = [];
        foreach ($rows as $r) {
            $productId = (int) ($r[$idIdx] ?? 0);
            if ($productId <= 0 || !isset($existing[$productId])) {
                continue;
            }
            $overrides = [];
            foreach ($overrideCols as $i => $key) {
                $value = $r[$i] ?? null;
                if (!empty($value) || $value == 0) {
                    $overrides[$key] = $value;
                }
            }
            $data[] = [
                'integration_id' => $integration->id,
                'product_id' => $productId,
                'overrides' => json_encode($overrides),
            ];
        }

        $imported = 0;
        foreach (array_chunk($data, 1000) as $chunk) {
            IntegrationProduct::upsert($chunk, ['integration_id', 'product_id'], ['overrides']);
            $imported += count($chunk);
        }

        return redirect()->route('crafter.integration-products.index', $integration)
            ->with(['message' => "Zaktualizowano {$imported} wierszy z CSV"]);
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
        foreach ($lines as $line) {
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
     * Eksport CSV w formacie identycznym jak stary arkusz Google integracji:
     * id, external_id, product_code, name, ean, enabled, overrides_name, overrides_ean, overrides_enabled.
     */
    public function exportCsv(Integration $integration): StreamedResponse
    {
        $integration->addAllEnabledProducts();

        $overrideKeys = ['name', 'ean', 'enabled'];
        $filename = $integration->type . '-' . Str::slug($integration->name) . '-' . date('Ymd') . '.csv';

        return response()->streamDownload(function () use ($integration, $overrideKeys) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");

            $headers = ['id', 'external_id', 'product_code'];
            foreach ($overrideKeys as $k) {
                $headers[] = $k;
                $headers[] = 'overrides_' . $k;
            }
            fputcsv($out, $headers);

            IntegrationProduct::with('product', 'integrationSource.template')
                ->where('integration_id', $integration->id)
                ->chunk(500, function ($items) use ($overrideKeys, $out) {
                    foreach ($items as $item) {
                        if (!$item->product) {
                            continue;
                        }
                        app()->setLocale($item->integrationSource?->template?->locale ?? 'pl');
                        $p = $item->product;
                        $ov = $item->overrides ?? [];
                        $row = [$p->id, $p->external_id, $p->product_code];
                        foreach ($overrideKeys as $k) {
                            $v = $p->{$k} ?? null;
                            $row[] = is_bool($v) ? (int) $v : ($v ?? '');
                            $row[] = $ov[$k] ?? '';
                        }
                        fputcsv($out, $row);
                    }
                });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Export
     */
    public function export(IndexIntegrationProductRequest $request, Integration $integration): BinaryFileResponse
    {

        set_time_limit(0);

        $integration->addAllEnabledProducts();

        $slug = Str::slug($integration->name);
        $filename = "{$integration->type}-integration-{$slug}-" . time();
        if ($integration->type === 'prestashop') {
            return Excel::download(new PrestashopIntegrationProductsExport($integration), "$filename.csv");
        } elseif ($integration->type === 'litecart') {
            // LiteCart connector accepts the same product payload shape as Prestashop export.
            return Excel::download(new PrestashopIntegrationProductsExport($integration), "$filename.csv");
        } elseif ($integration->type === 'selly') {
            return Excel::download(new SellyIntegrationProductsExport($integration), "$filename.csv");
        } elseif ($integration->type === 'baselinker') {
            return Excel::download(new SellyIntegrationProductsExport($integration), "$filename.csv");
        }

        abort(422, 'Unsupported integration type for export: ' . $integration->type);

    }
}
