<?php

namespace App\Services\Ebay;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Klient oficjalnego eBay Browse API (OAuth client_credentials).
 * Pobiera publiczne oferty sprzedawcy + aspekty (Herstellernummer / EAN).
 * Wzorzec: App\Services\BaseLinker\BaseLinkerClient.
 */
class EbayBrowseClient
{
    /**
     * Nazwy atrybutu „numer części producenta" w językach rynków eBay — znormalizowane
     * (bez akcentów, małe litery, znaki nie-alfanumeryczne → spacja). Konkurent NIE wypełnia
     * strukturalnego `mpn`, więc bez tej listy rynki inne niż DE wracają z pustym HN
     * i nie da się ich zmapować na nasze produkty.
     */
    private const MPN_ASPECTS = [
        'herstellernummer', 'hersteller teilenummer',                                 // DE
        'numero de piece fabricant', 'numero de piece du fabricant',                  // FR
        'reference fabricant', 'reference du fabricant',                              // FR
        'numero de pieza del fabricante', 'numero de pieza fabricante',               // ES
        'referencia del fabricante',                                                  // ES
        'numero di parte produttore', 'numero parte produttore',                      // IT
        'codice ricambio originale',                                                  // IT
        'manufacturer part number', 'mpn',                                            // GB/US
    ];

    /** Nazwy atrybutu EAN/GTIN w językach rynków (znormalizowane jak wyżej). */
    private const EAN_ASPECTS = [
        'ean', 'gtin', 'ean gtin',
        'codigo de barras',   // ES
        'codice a barre',     // IT
        'code barres',        // FR
    ];

    /**
     * Etykiety numeru artykułu w OPISIE oferty (fallback, gdy brak atrybutu strukturalnego).
     * Na eBay.es konkurent nie podaje ani numeru części, ani EAN — jedyne źródło to opis
     * („Nº de artículo:  29.212").
     */
    private const ARTICLE_NR_LABELS = [
        'Artikel[\s\-]*Nr',                        // DE  „ArtikelNr.: 20.009"
        'N[o°º]?\.?\s*de\s+art[ií]culo',           // ES  „Nº de artículo: 29.212"
        'Num[eé]ro\s+d[\'’]article',               // FR
        'R[eé]f[eé]rence(?:\s+fabricant)?',        // FR
        'Numero\s+articolo',                       // IT
        'Codice\s+(?:articolo|ricambio)',          // IT
        '(?:Item|Article|Part)\s+number',          // GB/US
    ];

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
     * Wszystkie oferty sprzedawcy na rynku tego klienta (paginacja).
     * Słowa-zalążki MUSZĄ być w języku rynku — np. EBAY_FR łapie tylko „protection sous moteur",
     * a nie niemieckie „Unterfahrschutz". Dlatego keywordy przychodzą z konfiguracji rynku (EbayScrapService::MARKETS).
     * @param  list<string>  $keywords  słowa kluczowe rynku (różne rankingi „Best Match" → szersza pokrywa)
     * @return array<int,array{external_id:string,title:?string,price:?string,currency:string,url:?string}>
     */
    public function searchSeller(string $seller, array $keywords): array
    {
        // Domyślny sort „Best Match" przy głębokiej paginacji GUBI część ofert (nie trafiają na żadną stronę).
        // Dlatego pytamy kilkoma słowami (różne rankingi) i scalamy wynik (dedup po itemId).
        $keywords = array_values(array_unique(array_filter(array_map('trim', $keywords))));
        if (empty($keywords)) {
            $keywords = ['Unterfahrschutz'];
        }

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
                    $name = self::normalizeAspectName((string) ($a['name'] ?? ''));
                    if ($hn === null && in_array($name, self::MPN_ASPECTS, true)) {
                        $hn = $a['value'] ?? null;
                    }
                    if ($ean === null && (in_array($name, self::EAN_ASPECTS, true) || str_contains($name, 'ean'))) {
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

    /** Nazwa atrybutu → porównywalna postać: bez akcentów, małe litery, separatory jako spacje. */
    private static function normalizeAspectName(string $name): string
    {
        $ascii = Str::ascii($name);                                   // „Numéro" → „Numero", „Nº" → „No"

        return trim(strtolower((string) preg_replace('/[^a-z0-9]+/i', ' ', $ascii)));
    }

    /**
     * Wyłuskuje numer artykułu z opisu oferty (gdy brak strukturalnego herstellernummer).
     * Scut wpisuje go w opisie w języku rynku — „ArtikelNr.: 20.009" (DE),
     * „Nº de artículo:  29.212" (ES) itd. Zwraca np. „20.009", „06.048ALU" albo null.
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

        // Etykieta bywa w opisie kilka razy (i w kilku wariantach) — bierzemy pierwszą,
        // po której naprawdę stoi numer w formacie „12.345" / „06.048ALU".
        $pattern = '/(?:' . implode('|', self::ARTICLE_NR_LABELS) . ')\.?\s*:?\s*([^\r\n<]+)/iu';
        if (preg_match_all($pattern, $text, $all)) {
            foreach ($all[1] as $tail) {
                if (preg_match('/[0-9]{1,4}[.\-][0-9]{1,5}[A-Za-z0-9.\-]*/', $tail, $mm)) {
                    return trim($mm[0]);
                }
            }
        }

        return null;
    }
}
