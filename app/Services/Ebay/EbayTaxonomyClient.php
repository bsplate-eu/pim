<?php

namespace App\Services\Ebay;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;

/**
 * Klient eBay Taxonomy API — kompatybilność pojazdów (kType) z bazy pojazdów eBaya.
 * Token aplikacyjny (client_credentials) jak w EbayBrowseClient — bez OAuth usera.
 *
 * Przepływ: categoryTreeId(EBAY_DE) → compatibilityProperties(kategoria aukcji)
 * → compatibilityPropertyValues(np. KType, filtry marka/model/rok) → numery kType.
 */
class EbayTaxonomyClient
{
    private Client $http;
    private string $api = 'https://api.ebay.com';

    /** Ile wartości aspektu FREE_TEXT trzymamy w cache jako podpowiedzi (dłuższe listy pomijamy). */
    private const MAX_INLINE_VALUES = 200;

    public function __construct(
        private string $clientId,
        private string $clientSecret,
    ) {
        $this->http = new Client(['timeout' => 30, 'http_errors' => false]);
    }

    /** Application token — ten sam cache co EbayBrowseClient (ten sam klucz md5(clientId)). */
    private function token(): string
    {
        return Cache::remember('ebay.token.' . md5($this->clientId), 6600, function () {
            $res = $this->http->post($this->api . '/identity/v1/oauth2/token', [
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret),
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'form_params' => [
                    'grant_type' => 'client_credentials',
                    'scope' => 'https://api.ebay.com/oauth/api_scope',
                ],
            ]);
            $body = json_decode((string) $res->getBody(), true);
            if ($res->getStatusCode() !== 200 || empty($body['access_token'])) {
                throw new \RuntimeException('eBay token error (' . $res->getStatusCode() . '): ' . json_encode($body));
            }

            return $body['access_token'];
        });
    }

    private function get(string $path, array $query): array
    {
        $res = $this->http->get($this->api . $path, [
            'headers' => ['Authorization' => 'Bearer ' . $this->token(), 'Accept' => 'application/json'],
            'query' => $query,
        ]);
        // 204 = eBay nie zna takiej kombinacji (np. marka spoza bazy) — to pusty wynik, nie błąd.
        if ($res->getStatusCode() === 204) {
            return [];
        }
        $d = json_decode((string) $res->getBody(), true);
        if ($res->getStatusCode() !== 200) {
            throw new \RuntimeException('Taxonomy ' . $path . ' (' . $res->getStatusCode() . '): ' . ($d['errors'][0]['message'] ?? json_encode($d)));
        }

        return $d ?? [];
    }

    /** ID drzewa kategorii rynku (EBAY_DE → własne drzewo). Cache na dobę — to się nie zmienia. */
    public function categoryTreeId(string $marketplace = 'EBAY_DE'): string
    {
        return Cache::remember('ebay.cattree.' . $marketplace, 86400, function () use ($marketplace) {
            $d = $this->get('/commerce/taxonomy/v1/get_default_category_tree_id', ['marketplace_id' => $marketplace]);

            return (string) $d['categoryTreeId'];
        });
    }

    /** Właściwości pojazdów wspierane w danej kategorii (np. KType, Marke, Modell…). */
    public function compatibilityProperties(string $treeId, string $categoryId): array
    {
        return Cache::remember("ebay.tax.props.{$treeId}.{$categoryId}", 604800, function () use ($treeId, $categoryId) {
            $d = $this->get("/commerce/taxonomy/v1/category_tree/{$treeId}/get_compatibility_properties", [
                'category_id' => $categoryId,
            ]);

            return $d['compatibilityProperties'] ?? [];
        });
    }

    /**
     * Item Specifics (aspekty) wymagane/dopuszczalne w kategorii — podstawa wystawiania oferty.
     * To CO INNEGO niż compatibilityProperties() (tamto = baza pojazdów, kType/fitment).
     *
     * Nazwy aspektów są własnością rynku: kategoria 14769 na DE ma „Hersteller", 9886 na FR
     * „Marque" + „Numéro de pièce fabricant" — dlatego czytamy je z eBaya per rynek, nie zgadujemy.
     *
     * Zwraca listę:
     *   ['name'=>string, 'required'=>bool, 'mode'=>'FREE_TEXT'|'SELECTION_ONLY',
     *    'cardinality'=>'SINGLE'|'MULTI', 'value_count'=>int, 'values'=>list<string>]
     *
     * `values` wypełniamy tylko tam, gdzie mają sens w UI mapowania: dla SELECTION_ONLY zawsze
     * (bez listy nie da się wybrać), dla FREE_TEXT wyłącznie gdy lista jest krótka — „Hersteller"
     * ma 5878 pozycji i wpychanie ich do JSON-a kategorii zamieniłoby cache w kilkumegabajtowy blob.
     */
    public function itemAspectsForCategory(string $treeId, string $categoryId): array
    {
        return Cache::remember("ebay.tax.aspects.{$treeId}.{$categoryId}", 604800, function () use ($treeId, $categoryId) {
            $d = $this->get("/commerce/taxonomy/v1/category_tree/{$treeId}/get_item_aspects_for_category", [
                'category_id' => $categoryId,
            ]);

            return collect($d['aspects'] ?? [])->map(function (array $a) {
                $con = $a['aspectConstraint'] ?? [];
                $mode = (string) ($con['aspectMode'] ?? 'FREE_TEXT');
                $values = array_values(array_filter(array_column($a['aspectValues'] ?? [], 'localizedValue')));

                return [
                    'name' => (string) ($a['localizedAspectName'] ?? ''),
                    'required' => (bool) ($con['aspectRequired'] ?? false),
                    'mode' => $mode,
                    'cardinality' => (string) ($con['itemToAspectCardinality'] ?? 'SINGLE'),
                    'value_count' => count($values),
                    'values' => ($mode === 'SELECTION_ONLY' || count($values) <= self::MAX_INLINE_VALUES)
                        ? $values
                        : [],
                ];
            })->filter(fn (array $a) => $a['name'] !== '')->values()->all();
        });
    }

    /**
     * Podpowiedzi kategorii po nazwie (ekran „Kategorie i parametry" — wyszukiwarka).
     * Zwraca ['id'=>string, 'name'=>string, 'path'=>string] — path z przodków, od korzenia.
     */
    public function categorySuggestions(string $treeId, string $query): array
    {
        $d = $this->get("/commerce/taxonomy/v1/category_tree/{$treeId}/get_category_suggestions", ['q' => $query]);

        return collect($d['categorySuggestions'] ?? [])->map(function (array $s) {
            // Przodkowie przychodzą od najbliższego — odwracamy, żeby ścieżka czytała się od korzenia.
            $path = collect($s['categoryTreeNodeAncestors'] ?? [])
                ->pluck('categoryName')
                ->reverse()
                ->push($s['category']['categoryName'] ?? '')
                ->filter()
                ->implode(' → ');

            return [
                'id' => (string) ($s['category']['categoryId'] ?? ''),
                'name' => (string) ($s['category']['categoryName'] ?? ''),
                'path' => $path,
            ];
        })->filter(fn (array $c) => $c['id'] !== '')->values()->all();
    }

    /**
     * Wartości właściwości pojazdu — z opcjonalnym zawężeniem innymi właściwościami.
     * $filters = ['Marke' => 'Volkswagen', 'Modell' => 'Eos'] → filter=Marke:Volkswagen,Modell:Eos
     * @return list<string>
     */
    public function compatibilityPropertyValues(string $treeId, string $categoryId, string $property, array $filters = []): array
    {
        $query = [
            'category_id' => $categoryId,
            'compatibility_property' => $property,
        ];
        if ($filters !== []) {
            $query['filter'] = collect($filters)->map(fn ($v, $k) => "{$k}:{$v}")->implode(',');
        }

        // Baza pojazdów eBaya zmienia się raz na rocznik, a masowa wysyłka fitmentu chodzi
        // paczkami (każda = nowy proces) i bez tego cache'u pytałaby wciąż o te same listy
        // marek i modeli — dzienny limit Taxonomy (5000) kończył się w pół drogi.
        $key = 'ebay.tax.' . md5($treeId . '|' . json_encode($query));

        return Cache::remember($key, 604800, function () use ($treeId, $query) {
            $d = $this->get("/commerce/taxonomy/v1/category_tree/{$treeId}/get_compatibility_property_values", $query);

            return collect($d['compatibilityPropertyValues'] ?? [])->pluck('value')->all();
        });
    }
}
