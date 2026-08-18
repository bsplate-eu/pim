<?php

namespace App\Http\Controllers\Admin\Connect\Marketplace;

use App\Http\Controllers\Admin\Controller;
use App\Models\Attribute;
use App\Models\Ebay\EbayCategory;
use App\Models\Scrap\EbaySettings;
use App\Services\Ebay\EbayTaxonomyClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Argo Connect → Marketplace → eBay → Kategorie i parametry.
 * Wzorzec: Connect\Marketplace\AllegroCategoryController z OMS ARGO.
 *
 * Wyszukujesz kategorię (po nazwie lub ID) → „Aktywuj" (zaciągamy aspekty = Item Specifics)
 * → mapujesz aspekty na atrybuty PIM / pola produktu / stałe. Aktywne kategorie zasilą
 * dropdown w schemacie wystawiania. Nie zaciągamy całego drzewa eBaya — tylko wyszukane.
 *
 * Różnice względem wzorca Allegro, wymuszone przez eBay:
 *  • kluczem jest para (rynek, kategoria) — ta sama półka ma inne ID i inne drzewo na każdym
 *    rynku (DE 14769/drzewo 77, FR 9886/drzewo 71), a nazwy aspektów są przetłumaczone;
 *  • Taxonomy API chodzi na tokenie APLIKACYJNYM, więc ekran działa BEZ połączonego konta OAuth
 *    — wystarczą klucze w Integracje → Ebay;
 *  • nie ma leniwego drzewa (Allegro ma `getCategories(parent)`); u eBaya podpowiedzi z
 *    `get_category_suggestions` zwracają pełną ścieżkę i to w zupełności wystarcza.
 */
class EbayCategoryController extends Controller
{
    /** Rynki, na których wystawiamy — te same, które obsługuje EbaySellClient. */
    private const MARKETPLACES = ['EBAY_DE', 'EBAY_FR', 'EBAY_ES', 'EBAY_IT', 'EBAY_PL', 'EBAY_AT', 'EBAY_GB'];

    public function index(): Response
    {
        $settings = EbaySettings::first();

        return Inertia::render('Connect/Marketplace/Ebay/Categories/Index', [
            'categories' => EbayCategory::orderBy('marketplace')->orderBy('category_name')->get()
                ->map(fn (EbayCategory $c) => [
                    'id' => $c->id,
                    'marketplace' => $c->marketplace,
                    'category_id' => $c->category_id,
                    'category_name' => $c->category_name,
                    'category_path' => $c->category_path,
                    'leaf' => $c->leaf,
                    'active' => $c->active,
                    'aspects' => $c->aspects ?? [],
                    'aspect_map' => $c->aspect_map ?? [],
                    'unmapped_required' => $c->unmappedRequired(),
                    'last_synced_at' => $c->last_synced_at?->toIso8601String(),
                ]),
            // „Nasze parametry" do mapowania = atrybuty PIM.
            'attributes' => Attribute::orderBy('order')->orderBy('id')->get()
                ->map(fn (Attribute $a) => ['id' => $a->id, 'name' => $a->name]),
            // Pola produktu, na które też można wskazać aspekt (poza atrybutami).
            'productFields' => [
                ['key' => 'product_code', 'label' => 'SKU (kod produktu)'],
                ['key' => 'name', 'label' => 'Nazwa produktu'],
                ['key' => 'ean', 'label' => 'EAN'],
                ['key' => 'width', 'label' => 'Szerokość'],
                ['key' => 'weight', 'label' => 'Waga'],
            ],
            'marketplaces' => self::MARKETPLACES,
            'hasCredentials' => (bool) $settings?->hasCredentials(),
        ]);
    }

    /** Wyszukiwarka kategorii: po ID (same cyfry) lub po nazwie/frazie (get_category_suggestions). */
    public function search(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'max:200'],
            'marketplace' => ['required', 'string', 'max:16'],
        ]);

        $taxonomy = $this->taxonomy();
        if (! $taxonomy) {
            return response()->json(['error' => 'Brak kluczy eBay — uzupełnij je w Integracje → Ebay.', 'results' => []]);
        }

        $marketplace = strtoupper($data['marketplace']);
        $q = trim($data['q']);

        try {
            $treeId = $taxonomy->categoryTreeId($marketplace);

            // Samo ID: nie ma czego szukać — sprawdzamy, czy kategoria w ogóle przyjmuje aspekty
            // (a przyjmuje je wyłącznie liść), i pokazujemy ją jako jedyny wynik.
            if (ctype_digit($q)) {
                $aspects = $taxonomy->itemAspectsForCategory($treeId, $q);

                return response()->json([
                    'error' => $aspects === [] ? "Kategoria {$q} nie zwróciła aspektów — sprawdź, czy to ID z API (nie z adresu strony) i czy to liść." : null,
                    'results' => $aspects === [] ? [] : [['id' => $q, 'name' => $q, 'path' => '—']],
                ]);
            }

            return response()->json(['error' => null, 'results' => $taxonomy->categorySuggestions($treeId, $q)]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage(), 'results' => []]);
        }
    }

    /** „Aktywuj" — zaciąga aspekty kategorii z Taxonomy i zapisuje ją jako aktywną. */
    public function activate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'marketplace' => ['required', 'string', 'max:16'],
            'category_id' => ['required', 'string', 'max:50'],
            'category_name' => ['nullable', 'string', 'max:255'],
            'category_path' => ['nullable', 'string', 'max:255'],
        ]);

        $taxonomy = $this->taxonomy();
        if (! $taxonomy) {
            return response()->json(['error' => 'Brak kluczy eBay — uzupełnij je w Integracje → Ebay.'], 422);
        }

        $marketplace = strtoupper($data['marketplace']);

        try {
            $treeId = $taxonomy->categoryTreeId($marketplace);
            $aspects = $taxonomy->itemAspectsForCategory($treeId, $data['category_id']);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        // Aspekty zwraca wyłącznie liść — pusta odpowiedź znaczy, że wystawić się tu nie da.
        if ($aspects === []) {
            return response()->json([
                'error' => "Kategoria {$data['category_id']} ({$marketplace}) nie zwróciła aspektów — nie jest liściem albo ID jest z adresu strony, nie z API.",
            ], 422);
        }

        $category = EbayCategory::updateOrCreate(
            ['marketplace' => $marketplace, 'category_id' => $data['category_id']],
            [
                'category_name' => $data['category_name'] ?: $data['category_id'],
                'category_path' => $data['category_path'],
                'category_tree_id' => $treeId,
                'leaf' => true,
                'active' => true,
                'aspects' => $aspects,
                'last_synced_at' => now(),
            ]
        );

        return response()->json([
            'ok' => true,
            'id' => $category->id,
            'aspects_count' => count($aspects),
            'required_count' => count($category->requiredAspects()),
        ]);
    }

    /** Zapisz mapowanie aspektów (aspekt eBay → atrybut PIM / pole produktu / stała). */
    public function updateMapping(Request $request, EbayCategory $category): RedirectResponse
    {
        $data = $request->validate([
            'aspect_map' => ['nullable', 'array'],
            'active' => ['nullable', 'boolean'],
        ]);

        $category->update([
            'aspect_map' => $this->cleanMap($data['aspect_map'] ?? []),
            'active' => $data['active'] ?? $category->active,
        ]);

        $missing = $category->fresh()->unmappedRequired();

        return back()->with(
            'success',
            $missing === []
                ? 'Mapowanie zapisane — kategoria gotowa do wystawiania.'
                : 'Mapowanie zapisane. Wymagane bez źródła: '.implode(', ', $missing).'.'
        );
    }

    /** Ponownie zaciągnij aspekty z eBaya (gdy kategoria zmieniła wymogi). Omija cache Taxonomy. */
    public function refresh(EbayCategory $category): RedirectResponse
    {
        $taxonomy = $this->taxonomy();
        if (! $taxonomy) {
            return back()->with('error', 'Brak kluczy eBay.');
        }

        try {
            $treeId = $category->category_tree_id ?: $taxonomy->categoryTreeId($category->marketplace);
            \Illuminate\Support\Facades\Cache::forget("ebay.tax.aspects.{$treeId}.{$category->category_id}");
            $aspects = $taxonomy->itemAspectsForCategory($treeId, $category->category_id);
        } catch (\Throwable $e) {
            return back()->with('error', 'Nie udało się odświeżyć: '.$e->getMessage());
        }

        $category->update([
            'aspects' => $aspects,
            'category_tree_id' => $treeId,
            'last_synced_at' => now(),
        ]);

        return back()->with('success', 'Aspekty odświeżone z eBaya ('.count($aspects).').');
    }

    public function destroy(EbayCategory $category): RedirectResponse
    {
        $category->delete();

        return back()->with('success', 'Kategoria usunięta.');
    }

    /** Klient Taxonomy na kluczach z ustawień (token aplikacyjny — bez OAuth usera). */
    private function taxonomy(): ?EbayTaxonomyClient
    {
        $settings = EbaySettings::first();
        if (! $settings || ! $settings->hasCredentials()) {
            return null;
        }

        return new EbayTaxonomyClient($settings->client_id, $settings->client_secret);
    }

    /**
     * Wyrzuć z mapy wpisy bez treści — front wysyła też aspekty tknięte i porzucone
     * (wybrane źródło, potem wyczyszczone). Bez tego `unmappedRequired()` widziałby
     * „źródło jest" i przepuściłby kategorię, której nie da się wystawić.
     *
     * @param  array<string,mixed>  $map
     * @return array<string,array>
     */
    private function cleanMap(array $map): array
    {
        $clean = [];

        foreach ($map as $aspect => $entry) {
            if (! is_array($entry) || empty($entry['source'])) {
                continue;
            }

            $kept = match ($entry['source']) {
                EbayCategory::SOURCE_FIXED => trim((string) ($entry['value'] ?? '')) !== ''
                    ? ['source' => EbayCategory::SOURCE_FIXED, 'value' => trim((string) $entry['value'])]
                    : null,
                EbayCategory::SOURCE_ATTRIBUTE => ! empty($entry['attribute_id'])
                    ? ['source' => EbayCategory::SOURCE_ATTRIBUTE, 'attribute_id' => (int) $entry['attribute_id']]
                    : null,
                EbayCategory::SOURCE_PRODUCT_FIELD => ! empty($entry['field'])
                    ? ['source' => EbayCategory::SOURCE_PRODUCT_FIELD, 'field' => (string) $entry['field']]
                    : null,
                default => null,
            };

            if ($kept !== null) {
                $clean[(string) $aspect] = $kept;
            }
        }

        return $clean;
    }
}
