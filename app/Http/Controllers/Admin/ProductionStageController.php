<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductionItem;
use App\Models\ProductionStage;
use App\Services\Production\StageAssigner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Produkcja → Ustawienia → Etapy.
 *
 * Slownik etapow razem z przedzialami sprzedazy, po ktorych etap jest
 * przypisywany automatycznie. Samo przypisanie robi StageAssigner, odpalany
 * przyciskiem — nie dzieje sie nic samo z siebie.
 */
class ProductionStageController extends Controller
{
    /** Progi histogramu pokazywanego przy ustawianiu przedzialow. */
    private const BUCKETS = [
        [0, 0],
        [1, 4],
        [5, 9],
        [10, 19],
        [20, 49],
        [50, 99],
        [100, null],
    ];

    public function index(Request $request): Response
    {
        return Inertia::render('Production/Settings', [
            'stages' => ProductionStage::orderBy('position')->orderBy('id')->get()
                ->map(fn (ProductionStage $stage) => [
                    'id' => $stage->id,
                    'name' => $stage->name,
                    'color' => $stage->color,
                    'sales_from' => $stage->sales_from,
                    'sales_to' => $stage->sales_to,
                    'position' => $stage->position,
                    'codes' => $stage->items()->count(),
                ])
                ->values(),
            'histogram' => $this->histogram(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['position'] = (int) (ProductionStage::max('position') ?? 0) + 1;

        ProductionStage::create($data);

        return back()->with(['message' => 'Etap dodany']);
    }

    public function update(Request $request, ProductionStage $stage): RedirectResponse
    {
        $stage->update($this->validated($request));

        return back()->with(['message' => 'Etap zapisany']);
    }

    public function destroy(ProductionStage $stage): RedirectResponse
    {
        // Kody po prostu traca etap (FK ma nullOnDelete) — nie kasujemy danych produkcyjnych.
        $stage->delete();

        return back()->with(['message' => 'Etap usuniety']);
    }

    /** Zmiana kolejnosci — decyduje, ktory etap wygrywa przy nachodzacych przedzialach. */
    public function reorder(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'order' => ['required', 'array'],
            'order.*' => ['integer', 'exists:production_stages,id'],
        ]);

        foreach ($data['order'] as $index => $id) {
            ProductionStage::whereKey($id)->update(['position' => $index + 1]);
        }

        return back()->with(['message' => 'Kolejnosc zapisana']);
    }

    /** Przeliczenie etapow na zadanie. */
    public function recalculate(StageAssigner $assigner): JsonResponse
    {
        return response()->json($assigner->run());
    }

    /**
     * @return array<string,mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'sales_from' => ['nullable', 'integer', 'min:0'],
            'sales_to' => ['nullable', 'integer', 'min:0'],
        ], [
            'color.regex' => 'Kolor musi byc w formacie #rrggbb.',
        ]);

        // Odwrocony przedzial to najczestsza literowka przy wpisywaniu progow —
        // lepiej odbic ja na wejsciu niz tlumaczyc pozniej, czemu etap nic nie lapie.
        if ($data['sales_from'] !== null && $data['sales_to'] !== null && $data['sales_to'] < $data['sales_from']) {
            abort(422, 'Gorna granica przedzialu nie moze byc mniejsza od dolnej.');
        }

        return $data;
    }

    /**
     * Rozklad sprzedazy 12M po kodach — pokazywany przy ustawianiu progow, zeby
     * nie ustawiac ich w ciemno (68% katalogu siedzi ponizej 5 sztuk).
     *
     * @return list<array{label:string, from:int, to:int|null, count:int, percent:float}>
     */
    private function histogram(): array
    {
        $codes = Product::query()->distinct()->pluck('product_code');
        $sales = ProductionItem::pluck('sales_12m', 'product_code');
        $total = max($codes->count(), 1);

        $values = $codes->map(fn ($code) => (int) ($sales[$code] ?? 0));

        return collect(self::BUCKETS)->map(function (array $bucket) use ($values, $total) {
            [$from, $to] = $bucket;

            $count = $values->filter(
                fn (int $value) => $value >= $from && ($to === null || $value <= $to)
            )->count();

            return [
                'label' => $to === null ? $from . '+' : ($from === $to ? (string) $from : $from . '-' . $to),
                'from' => $from,
                'to' => $to,
                'count' => $count,
                'percent' => round($count / $total * 100, 1),
            ];
        })->values()->all();
    }
}
