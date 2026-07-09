<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\IntegrationProduct;
use App\Models\Product;
use App\Models\TranslationOverride;
use App\Services\ProductTranslationComposer;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Kolejka produktów wymagających ręcznej akceptacji tłumaczeń.
 *
 * Logika: produkt trafia do kolejki gdy:
 *   - enabled=false (zwykle świeży import z Sumpguard), LUB
 *   - nie ma żadnego wpisu `translation_overrides` dla `name` (czyli wszystkie sloty wciąż są z Sumpguard fallback).
 *
 * Human-in-the-loop: user przegląda, ewentualnie uruchamia auto-translate (composer),
 * dopisuje brakujące frazy do matrycy (osobny moduł), i klika "Zatwierdź" → enabled=true
 * + overrides.enabled=1 dla wszystkich IntegrationProduct (wpuszcza produkt do delta-sync).
 */
class TranslationReviewController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->get('search', ''));
        $query = $this->reviewQuery($search);

        // Sortowanie: ?sort=kolumna (asc) / ?sort=-kolumna (desc)
        $sort = (string) $request->get('sort', '-id');
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');
        if (!in_array($column, ['id', 'name_pl', 'coverage', 'status'], true)) {
            $column = 'id';
            $direction = 'desc';
            $sort = '-id';
        }

        $plExpr = "JSON_UNQUOTE(JSON_EXTRACT(name, '$.pl'))";
        // pokrycie = PL niepuste + każdy obcy locale niepusty i ≠ PL (ta sama definicja co licznik w tabeli)
        $coverageExpr = collect(ProductTranslationComposer::LOCALE_CHANNELS)->map(function ($locale) use ($plExpr) {
            $expr = "JSON_UNQUOTE(JSON_EXTRACT(name, '$.{$locale}'))";
            return $locale === 'pl'
                ? "(CASE WHEN {$expr} IS NOT NULL AND {$expr} <> '' THEN 1 ELSE 0 END)"
                : "(CASE WHEN {$expr} IS NOT NULL AND {$expr} <> '' AND {$expr} <> {$plExpr} THEN 1 ELSE 0 END)";
        })->implode(' + ');

        match ($column) {
            'name_pl'  => $query->orderByRaw("{$plExpr} {$direction}"),
            'coverage' => $query->orderByRaw("({$coverageExpr}) {$direction}"),
            'status'   => $query->orderBy('enabled', $direction),
            default    => $query->orderBy('id', $direction),
        };
        if ($column !== 'id') {
            $query->orderByDesc('id'); // stabilna paginacja przy remisach
        }

        $perPage = (int) $request->get('per_page', 30);
        $products = $query->paginate($perPage)->withQueryString();

        // Pokrycie = liczba RZECZYWISTYCH tłumaczeń: PL niepuste + każdy obcy locale niepusty i ≠ PL
        // (nie liczymy locków — composer celowo nie lockuje PL, bo to język źródłowy z feedu).
        $items = collect($products->items())->map(function (Product $p) {
            $translations = $p->getTranslations('name');
            $pl = trim((string) ($translations['pl'] ?? ''));
            $covered = 0;
            foreach (ProductTranslationComposer::LOCALE_CHANNELS as $locale) {
                $value = trim((string) ($translations[$locale] ?? ''));
                if ($value === '') continue;
                if ($locale !== 'pl' && $value === $pl) continue; // polski fallback ≠ tłumaczenie
                $covered++;
            }
            return [
                'id'              => $p->id,
                'external_id'     => $p->external_id,
                'product_code'    => $p->product_code,
                'name_pl'         => $p->getTranslation('name', 'pl', false),
                'name_de'         => $p->getTranslation('name', 'de', false),
                'enabled'         => $p->enabled,
                'locales_covered' => $covered,
                'locales_target'  => count(ProductTranslationComposer::LOCALE_CHANNELS),
            ];
        });

        // Przesłaniamy items w paginatorze nie ruszając meta:
        $payload = $products->toArray();
        $payload['data'] = $items;

        return Inertia::render('TranslationReview/Index', [
            'products' => $payload,
            'sort'     => $sort,
            'search'   => $search,
        ]);
    }

    public function autoTranslate(Request $request, Product $product, ProductTranslationComposer $composer)
    {
        $stats = $composer->apply($product->load('attributeValues.attribute'), 'review');

        if (!$stats['matched']) {
            return redirect()->back()->with([
                'message' => 'Brak dopasowania w matrycy — dopisz frazę i spróbuj ponownie.',
            ]);
        }

        return redirect()->back()->with([
            'message' => sprintf(
                'Wypełniono %d lokali + %d kont Allegro (pominięto chronionych: %d)',
                $stats['applied_locales'],
                $stats['applied_integrations'],
                $stats['skipped_locked']
            ),
        ]);
    }

    public function approve(Request $request, Product $product)
    {
        $this->approveProduct($product);

        return redirect()->back()->with(['message' => 'Produkt zatwierdzony i włączony do eksportu']);
    }

    /**
     * Masowe zatwierdzanie. Wejście:
     *   - ids: [int]            → zatwierdź wskazane
     *   - all: true (+ search)  → zatwierdź WSZYSTKIE pasujące do bieżącego filtra kolejki
     */
    public function approveBulk(Request $request)
    {
        $validated = $request->validate([
            'all'    => ['sometimes', 'boolean'],
            'search' => ['sometimes', 'nullable', 'string'],
            'ids'    => ['sometimes', 'array'],
            'ids.*'  => ['integer'],
        ]);

        if (!empty($validated['all'])) {
            $ids = $this->reviewQuery(trim((string) ($validated['search'] ?? '')))->pluck('id');
        } else {
            $ids = collect($validated['ids'] ?? []);
        }

        if ($ids->isEmpty()) {
            return redirect()->back()->with(['message' => 'Nie wskazano produktów do zatwierdzenia.']);
        }

        $count = 0;
        // chunk, by nie ładować tysięcy modeli naraz
        $ids->chunk(200)->each(function ($chunk) use (&$count) {
            Product::whereIn('id', $chunk)->get()->each(function (Product $product) use (&$count) {
                $this->approveProduct($product);
                $count++;
            });
        });

        return redirect()->back()->with(['message' => "Zatwierdzono i włączono do eksportu: {$count} produktów"]);
    }

    /**
     * Masowe auto-tłumaczenie. Wejście jak approveBulk: ids[] albo all+search.
     * Dla każdego produktu woła composer->apply (wypełnia tłumaczenia z matrycy).
     */
    public function autoTranslateBulk(Request $request, ProductTranslationComposer $composer)
    {
        $validated = $request->validate([
            'all'    => ['sometimes', 'boolean'],
            'search' => ['sometimes', 'nullable', 'string'],
            'ids'    => ['sometimes', 'array'],
            'ids.*'  => ['integer'],
        ]);

        if (!empty($validated['all'])) {
            $ids = $this->reviewQuery(trim((string) ($validated['search'] ?? '')))->pluck('id');
        } else {
            $ids = collect($validated['ids'] ?? []);
        }

        if ($ids->isEmpty()) {
            return redirect()->back()->with(['message' => 'Nie wskazano produktów do tłumaczenia.']);
        }

        $matched = 0;
        $unmatched = 0;
        $ids->chunk(100)->each(function ($chunk) use ($composer, &$matched, &$unmatched) {
            Product::with('attributeValues.attribute')
                ->whereIn('id', $chunk)
                ->get()
                ->each(function (Product $product) use ($composer, &$matched, &$unmatched) {
                    $stats = $composer->apply($product, 'bulk');
                    $stats['matched'] ? $matched++ : $unmatched++;
                });
        });

        $msg = "Przetłumaczono z matrycy: {$matched} produktów";
        if ($unmatched > 0) {
            $msg .= " · bez dopasowania frazy (zostają do ręcznego): {$unmatched}";
        }

        return redirect()->back()->with(['message' => $msg]);
    }

    private function approveProduct(Product $product): void
    {
        TranslationOverride::$suppressObserver = true;
        try {
            $product->enabled = true;
            $product->save();

            // Włącz produkt we wszystkich IntegrationProduct (overrides.enabled = "1")
            IntegrationProduct::where('product_id', $product->id)
                ->get()
                ->each(function ($ip) {
                    $overrides = $ip->overrides ?? [];
                    $overrides['enabled'] = '1';
                    $ip->overrides = $overrides;
                    $ip->save();
                });
        } finally {
            TranslationOverride::$suppressObserver = false;
        }
    }

    /**
     * Bazowy query kolejki review (kryterium + wyszukiwarka), wspólny dla listy i masowego zatwierdzania.
     */
    private function reviewQuery(string $search = '')
    {
        $productMorph = (new Product())->getMorphClass();
        $lockedProductIds = TranslationOverride::query()
            ->where('translatable_type', $productMorph)
            ->where('field', 'name')
            ->whereIn('source', TranslationOverride::LOCKING_SOURCES)
            ->distinct()
            ->pluck('translatable_id');

        $query = Product::query()
            ->where(function ($q) use ($lockedProductIds) {
                $q->where('enabled', false)
                  ->orWhereNotIn('id', $lockedProductIds);
            })
            ->whereNotNull('source_id');

        // Wyszukiwarka: każde słowo musi wystąpić w PL, DE lub kodzie produktu.
        // COLLATE wymagane: JSON_UNQUOTE zwraca kolację binarną → LIKE byłby case-sensitive.
        if ($search !== '') {
            foreach (preg_split('/\s+/', $search) as $word) {
                $like = '%' . $word . '%';
                $query->where(function ($q) use ($like) {
                    $q->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(name, '$.pl')) COLLATE utf8mb4_unicode_ci LIKE ?", [$like])
                      ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(name, '$.de')) COLLATE utf8mb4_unicode_ci LIKE ?", [$like])
                      ->orWhere('product_code', 'like', $like);
                });
            }
        }

        return $query;
    }
}
