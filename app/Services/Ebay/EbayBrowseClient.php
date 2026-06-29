<?php

namespace App\Services\Ebay;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;

/**
 * Klient oficjalnego eBay Browse API (OAuth client_credentials).
 * Pobiera publiczne oferty sprzedawcy + aspekty (Herstellernummer / EAN).
 * Wzorzec: App\Services\BaseLinker\BaseLinkerClient.
 */
class EbayBrowseClient
{
    private Client $http;
    private string $api = 'https://api.ebay.com';

    public function __construct(
        private string $clientId,
        private string $clientSecret,
        private string $marketplace = 'EBAY_DE',
    ) {
        $this->http = new Client(['timeout' => 30, 'http_errors' => false]);
    }

    /** Application token (client_credentials), cache ~1h50m (ważny 2h). */
    public function token(): string
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

    private function authHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->token(),
            'X-EBAY-C-MARKETPLACE-ID' => $this->marketplace,
            'Accept' => 'application/json',
        ];
    }

    /** Szybki test: token + 1 strona wyników. Zwraca ['ok'=>bool,'message'=>..,'total'=>int]. */
    public function testConnection(string $seller, string $keyword = 'Unterfahrschutz'): array
    {
        try {
            $res = $this->http->get($this->api . '/buy/browse/v1/item_summary/search', [
                'headers' => $this->authHeaders(),
                'query' => ['q' => $keyword, 'filter' => 'sellers:{' . $seller . '}', 'limit' => 1],
            ]);
            $d = json_decode((string) $res->getBody(), true);
            if ($res->getStatusCode() !== 200) {
                return ['ok' => false, 'message' => 'eBay API ' . $res->getStatusCode() . ': ' . ($d['errors'][0]['message'] ?? json_encode($d))];
            }
            return ['ok' => true, 'message' => 'Połączenie OK.', 'total' => (int) ($d['total'] ?? 0)];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Wszystkie oferty sprzedawcy (paginacja).
     * @return array<int,array{external_id:string,title:?string,price:?string,currency:string,url:?string}>
     */
    public function searchSeller(string $seller, string $keyword = 'Unterfahrschutz'): array
    {
        // Domyślny sort „Best Match" przy głębokiej paginacji GUBI część ofert (nie trafiają na żadną stronę).
        // Dlatego pytamy kilkoma słowami kluczowymi (różne rankingi) i scalamy wynik (dedup po itemId).
        $keywords = array_values(array_unique(array_filter([
            $keyword, 'Unterfahrschutz', 'Stahl', 'Aluminium', 'Getriebe', 'Motor',
        ])));

        $out = [];
        $seen = [];
        foreach ($keywords as $kw) {
            $offset = 0;
            $limit = 200;
            do {
                $res = $this->http->get($this->api . '/buy/browse/v1/item_summary/search', [
                    'headers' => $this->authHeaders(),
                    'query' => ['q' => $kw, 'filter' => 'sellers:{' . $seller . '}', 'limit' => $limit, 'offset' => $offset],
                ]);
                $d = json_decode((string) $res->getBody(), true);
                if ($res->getStatusCode() !== 200) {
                    throw new \RuntimeException('eBay search error (' . $res->getStatusCode() . '): ' . json_encode($d));
                }
                $items = $d['itemSummaries'] ?? [];
                foreach ($items as $it) {
                    $id = $it['itemId'] ?? null;
                    if (! $id || isset($seen[$id])) {
                        continue;
                    }
                    $seen[$id] = true;
                    $out[] = [
                        'external_id' => $id,
                        'title' => $it['title'] ?? null,
                        'price' => $it['price']['value'] ?? null,
                        'currency' => $it['price']['currency'] ?? 'EUR',
                        'url' => $it['itemWebUrl'] ?? null,
                    ];
                }
                $offset += $limit;
            } while (count($items) > 0 && $offset < 10000);
        }

        return $out;
    }

    /** getItem → [herstellernummer, ean]. Tani strzał; przy błędzie [null,null]. */
    public function itemAspects(string $itemId): array
    {
        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $res = $this->http->get($this->api . '/buy/browse/v1/item/' . rawurlencode($itemId), [
                'headers' => $this->authHeaders(),
            ]);
            $code = $res->getStatusCode();

            if ($code === 200) {
                $item = json_decode((string) $res->getBody(), true);
                $hn = null;
                $ean = null;
                foreach (($item['localizedAspects'] ?? []) as $a) {
                    $name = strtolower($a['name'] ?? '');
                    if ($name === 'herstellernummer' || $name === 'hersteller-teilenummer') {
                        $hn = $a['value'] ?? null;
                    }
                    if (str_contains($name, 'ean')) {
                        $ean = $a['value'] ?? null;
                    }
                }
                if (! $ean && ! empty($item['gtin'])) {
                    $ean = $item['gtin'];
                }
                if (! $hn && ! empty($item['mpn'])) {
                    $hn = $item['mpn'];
                }
                // Brak strukturalnego HN → spróbuj wyłuskać „ArtikelNr.: …" z opisu oferty.
                if (! $hn) {
                    $hn = $this->articleNrFromDescription(
                        (string) ($item['description'] ?? '') . "\n" . (string) ($item['shortDescription'] ?? '')
                    );
                }
                return [$hn, $ean];
            }

            // 429 (rate limit) / 5xx — ponów z narastającym backoffem; inne błędy (404…) — odpuść
            if ($code === 429 || $code >= 500) {
                usleep($attempt * 600000); // 0.6s → 1.2s → 1.8s → 2.4s
                continue;
            }

            return [null, null];
        }

        return [null, null];
    }

    /**
     * Wyłuskuje numer artykułu z opisu oferty (gdy brak strukturalnego herstellernummer).
     * Scut wpisuje w opisie linię „ArtikelNr.: 20.009" (też „Artikel-Nr:", „Artikel Nr.:").
     * Zwraca np. „20.009", „06.048ALU", „00.502-1ALU" albo null.
     */
    private function articleNrFromDescription(string $html): ?string
    {
        if (trim($html) === '') {
            return null;
        }

        // Zachowaj łamania linii z HTML, potem zdejmij tagi.
        $html = preg_replace('/<\s*br\s*\/?>/i', "\n", $html);
        $html = preg_replace('/<\/(p|div|li|tr|h[1-6]|span)>/i', "\n", $html);
        $text = html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5);

        if (preg_match('/Artikel[\s\-]*Nr\.?\s*:?\s*([^\r\n<]+)/iu', $text, $m)
            && preg_match('/[0-9]{1,4}[.\-][0-9]{1,5}[A-Za-z0-9.\-]*/', $m[1], $mm)) {
            return trim($mm[0]);
        }

        return null;
    }
}
