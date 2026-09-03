<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Attribute;
use App\Models\Product;
use App\Models\ProductionItem;
use App\Models\ProductionStage;
use App\Services\Production\CodeGrouper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
     * Lista kodow produkcyjnych (bez powtorzen) do gridu produkcji.
     */
    public function index(Request $request, CodeGrouper $grouper): Response
    {
        // Atrybut „Materiał" (Stal/Aluminium) — dociagany tak samo jak na liscie produktow.
        $materialValues = Attribute::with('values')->where('slug', 'material')->first()?->values ?? collect();
        $materialValueIds = $materialValues->pluck('id')->all();
        $materialLabels = $materialValues->mapWithKeys(fn ($value) => [$value->slug => $value->name])->all();

        $stages = ProductionStage::orderBy('position')->orderBy('id')->get();

        // Dane produkcyjne — tylko dla kodow, na ktorych cos ustawiono albo wgrano.
        $items = ProductionItem::get(array_merge(
            ['product_code', 'sales_12m', 'stage_id'],
            array_values(self::FLAGS)
        ))->keyBy('product_code');

        // Kod wariantu => trzon. Tylko grupy zatwierdzone i tylko zaznaczone
        // warianty — odpiete zostaja osobnymi wierszami.
        $groupMap = $grouper->activeMap();

        $rows = Product::query()
            ->select('id', 'product_code', 'name')
            ->with(['attributeValues' => fn ($query) => $query->whereIn('attribute_values.id', $materialValueIds)])
            ->orderBy('product_code')
            ->orderBy('id')
            ->get()
            ->groupBy('product_code')
            ->map(function ($group) use ($materialLabels, $items) {
                // Reprezentant kodu = najstarszy produkt (najnizsze id) — po nim bierzemy nazwe.
                $product = $group->first();
                $slug = $product->attributeValues->first()?->slug;
                $item = $items[$product->product_code] ?? null;

                $row = [
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
                    // Sprzedaz 12M z raportu Subiekta. Kod bez wiersza w raporcie = 0.
                    'sales_12m' => (int) ($item?->sales_12m ?? 0),
                    'stage_id' => $item?->stage_id,
                    // Warianty wciagniete do tego wiersza (wypelniane nizej).
                    'group_codes' => [],
                ];

                // Znaczniki lecą pod nazwami z frontu — dodanie kolejnego to jeden wpis w FLAGS.
                foreach (self::FLAGS as $key => $column) {
                    $row[$key] = (bool) ($item?->{$column} ?? false);
                }

                return $row;
            })
            ->keyBy('product_code');

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
