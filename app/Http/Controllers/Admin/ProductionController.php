<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Attribute;
use App\Models\Product;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Argo PIM → Produkcja.
 *
 * Ekran startowy modulu: pelna lista kodow z bazy — tak jak tabela w cenniku.
 * Wszystkie wiersze ida do przegladarki naraz (1,5 tys. produktow), filtrowanie
 * i sortowanie robi DataGrid po stronie klienta — bez paginacji i bez round-tripow.
 */
class ProductionController extends Controller
{
    /**
     * Lista wszystkich produktow (kodow) do gridu produkcji.
     */
    public function index(Request $request): Response
    {
        // Atrybut „Materiał" (Stal/Aluminium) — dociagany tak samo jak na liscie produktow.
        $materialValues = Attribute::with('values')->where('slug', 'material')->first()?->values ?? collect();
        $materialValueIds = $materialValues->pluck('id')->all();
        $materialLabels = $materialValues->mapWithKeys(fn ($value) => [$value->slug => $value->name])->all();

        $rows = Product::query()
            ->select('id', 'product_code', 'name', 'ean', 'width', 'weight', 'enabled')
            ->with(['attributeValues' => fn ($query) => $query->whereIn('attribute_values.id', $materialValueIds)])
            ->orderBy('product_code')
            ->get()
            ->map(function ($product) use ($materialLabels) {
                $slug = $product->attributeValues->first()?->slug;

                return [
                    'product_id' => $product->id,
                    'product_code' => $product->product_code,
                    // Nazwy w bazie bywaja z encjami HTML (&quot;) — te same, co na liscie produktow.
                    'name' => htmlspecialchars_decode((string) $product->name),
                    'ean' => (string) $product->ean,
                    'width' => $product->width === null ? null : (float) $product->width,
                    'weight' => $product->weight === null ? null : (float) $product->weight,
                    'material' => $slug === null ? '' : ($materialLabels[$slug] ?? $slug),
                    'enabled' => (bool) $product->enabled,
                ];
            })
            ->values();

        return Inertia::render('Production/Index', [
            'rows' => $rows,
        ]);
    }
}
