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
