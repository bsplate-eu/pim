<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Attribute;
use App\Models\Product;
use App\Models\ProductionItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
     * Znaczniki przestawiane z gridu: nazwa z frontu => kolumna w bazie.
     * Bialalista — bez niej `setFlag` pozwalalby pisac po dowolnej kolumnie.
     */
    private const FLAGS = [
        'project' => 'has_project',
        'team_steel' => 'team_steel',
        'etap_1' => 'etap_1',
        'etap_2' => 'etap_2',
        'etap_3' => 'etap_3',
        'bez_wspornikow' => 'bez_wspornikow',
        'projekty_gotowe' => 'projekty_gotowe',
    ];

    /**
     * Etapy wykluczaja sie wzajemnie — kod jest na jednym etapie naraz.
     * Pilnujemy tego TUTAJ, a nie tylko w gridzie: procenty na ekranie licza
     * sie z zalozenia „suma etapow + bez etapu = 100%", wiec kod z dwoma
     * etapami rozjechalby cale podsumowanie.
     */
    private const STAGE_FLAGS = ['etap_1', 'etap_2', 'etap_3'];

    /**
     * Lista kodow produkcyjnych (bez powtorzen) do gridu produkcji.
     */
    public function index(Request $request): Response
    {
        // Atrybut „Materiał" (Stal/Aluminium) — dociagany tak samo jak na liscie produktow.
        $materialValues = Attribute::with('values')->where('slug', 'material')->first()?->values ?? collect();
        $materialValueIds = $materialValues->pluck('id')->all();
        $materialLabels = $materialValues->mapWithKeys(fn ($value) => [$value->slug => $value->name])->all();

        // Dane produkcyjne — tylko dla kodow, na ktorych cos ustawiono albo wgrano.
        $items = ProductionItem::get(array_merge(['product_code', 'sales_12m'], array_values(self::FLAGS)))
            ->keyBy('product_code');

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
                    // Sprzedaz 12M z raportu Subiekta. Kod bez wiersza w raporcie = 0.
                    'sales_12m' => (int) ($item?->sales_12m ?? 0),
                ];

                // Znaczniki lecą pod nazwami z frontu — dodanie kolejnego to jeden wpis w FLAGS.
                foreach (self::FLAGS as $key => $column) {
                    $row[$key] = (bool) ($item?->{$column} ?? false);
                }

                return $row;
            })
            ->values();

        return Inertia::render('Production/Index', [
            'rows' => $rows,
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

        $values = [self::FLAGS[$data['flag']] => $data['value']];

        // Zaznaczenie etapu zdejmuje pozostale. Odznaczenie nie rusza niczego —
        // kod po prostu wypada z etapow.
        if ($data['value'] && in_array($data['flag'], self::STAGE_FLAGS, true)) {
            foreach (self::STAGE_FLAGS as $stage) {
                if ($stage !== $data['flag']) {
                    $values[self::FLAGS[$stage]] = false;
                }
            }
        }

        $item = ProductionItem::updateOrCreate(['product_code' => $data['product_code']], $values);

        return response()->json([
            'product_code' => $data['product_code'],
            // Oddajemy komplet znacznikow — grid nie musi zgadywac, co serwer
            // zdjal przy wykluczaniu etapow.
            'flags' => collect(self::FLAGS)->map(fn ($column) => (bool) $item->{$column}),
        ]);
    }
}
