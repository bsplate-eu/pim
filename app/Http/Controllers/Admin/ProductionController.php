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
 * Ekran startowy modulu: lista kodow produkcyjnych. Jeden kod = jeden wiersz,
 * bo w PIM ten sam `product_code` powtarza sie dla kazdego auta, do ktorego
 * czesc pasuje (np. 18.201 to 21 wpisow) — z punktu widzenia produkcji to
 * ciagle jedna sztuka do zrobienia.
 *
 * Wszystkie wiersze ida do przegladarki naraz, filtrowanie i sortowanie robi
 * DataGrid po stronie klienta — bez paginacji i bez round-tripow.
 */
class ProductionController extends Controller
{
    /**
     * Lista kodow produkcyjnych (bez powtorzen) do gridu produkcji.
     */
    public function index(Request $request): Response
    {
        // Atrybut „Materiał" (Stal/Aluminium) — dociagany tak samo jak na liscie produktow.
        $materialValues = Attribute::with('values')->where('slug', 'material')->first()?->values ?? collect();
        $materialValueIds = $materialValues->pluck('id')->all();
        $materialLabels = $materialValues->mapWithKeys(fn ($value) => [$value->slug => $value->name])->all();

        $rows = Product::query()
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
                ];
            })
            ->values();

        return Inertia::render('Production/Index', [
            'rows' => $rows,
        ]);
    }
}
