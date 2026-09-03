<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductionItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Produkcja → Ustawienia → Wykluczenia.
 *
 * Lista wszystkich kodow z katalogu; zaznaczony kod znika z tabeli produkcji
 * i przestaje sie liczyc do czegokolwiek — sumy grupy, etapow, barometru.
 * Nic przy tym nie jest kasowane: odznaczenie przywraca kod w tym samym stanie.
 */
class ProductionExclusionController extends Controller
{
    /** Przestawia wykluczenie jednego kodu. */
    public function toggle(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'product_code' => ['required', 'string', Rule::exists('products', 'product_code')],
            'excluded' => ['required', 'boolean'],
        ]);

        ProductionItem::updateOrCreate(
            ['product_code' => $data['product_code']],
            ['excluded' => $data['excluded']],
        );

        return back()->with(['message' => $data['excluded'] ? 'Kod wykluczony' : 'Kod przywrocony']);
    }

    /** Masowe wykluczanie/przywracanie zaznaczonych kodow. */
    public function bulk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'codes' => ['required', 'array', 'min:1'],
            'codes.*' => ['string', Rule::exists('products', 'product_code')],
            'excluded' => ['required', 'boolean'],
        ]);

        foreach ($data['codes'] as $code) {
            ProductionItem::updateOrCreate(['product_code' => $code], ['excluded' => $data['excluded']]);
        }

        $slowo = $data['excluded'] ? 'wykluczonych' : 'przywroconych';

        return back()->with(['message' => count($data['codes']) . ' kodow ' . $slowo]);
    }

    /**
     * Dane zakladki „Wykluczenia".
     *
     * Wszystkie kody naraz (707 pozycji) — filtrowanie robi przegladarka, tak
     * samo jak w tabeli produkcji. Paginacja przy szukaniu konkretnego kodu
     * bardziej by przeszkadzala niz pomagala.
     *
     * @return array<string,mixed>
     */
    public static function settingsPayload(): array
    {
        $items = ProductionItem::get(['product_code', 'sales_12m', 'excluded'])->keyBy('product_code');

        $codes = Product::query()
            ->select('product_code', 'name')
            ->orderBy('product_code')
            ->get()
            ->groupBy('product_code')
            ->map(function ($group, $code) use ($items) {
                $item = $items[$code] ?? null;

                return [
                    'product_code' => $code,
                    'name' => htmlspecialchars_decode((string) $group->first()->name),
                    'sales_12m' => (int) ($item?->sales_12m ?? 0),
                    'excluded' => (bool) ($item?->excluded ?? false),
                ];
            })
            ->values();

        return [
            'codes' => $codes,
            'excluded_count' => $codes->where('excluded', true)->count(),
        ];
    }
}
