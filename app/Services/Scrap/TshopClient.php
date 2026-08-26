<?php

namespace App\Services\Scrap;

use Generator;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Driver sklepów na platformie „tshop" (Joomla, SSR) — własne sklepy Scut Protection:
 *   Hiszpania — cubrecarterprotect.es (URL produktu z sufiksem /a, kategorie /c)
 *   Niemcy 2  — scutprotection.de     (URL produktu z sufiksem /p, kategorie /k)
 *
 * sitemap.xml → URL-e produktów (filtr po sufiksie) → strona produktu.
 * ⚠️ Obie domeny (Cloudflare) serwują POPRAWNĄ sitemapę pod statusem HTTP 404 — stąd tolerancja 404
 * przy pobraniu + walidacja treści (<urlset). Bez tego driver widzi zero produktów.
 *
 * Dane produktu z JSON-LD Product, z dopięciem z DOM tam, gdzie sklep ma uboższy JSON-LD:
 *   herstellernummer ← mpn, a gdy brak → wiersz tabelki `tr[data-sku]` (2. komórka). Selektor po atrybucie,
 *                      bo etykieta jest językowa („Código del producto" / „Produktcode").
 *   ean              ← gtin13, a gdy brak → sku (na scutprotection.de sku = EAN13)
 *   external_id      = EAN (unikalny per produkt; kod artykułu NIE jest unikalny — 1 kod = kilka modeli)
 *   price (brutto)   ← offers.price · StrikethroughPrice = cena sprzed rabatu
 *
 * robots.txt: User-agent:* → Allow:/ (blokady Disallow dotyczą botów AI: ClaudeBot/GPTBot/Amazonbot — nie nas;
 * dla zwykłych agentów off-limit jest tylko /ajax_functions). Scrape do monitoringu cen = w zgodzie z polityką.
 */
class TshopClient implements ShopClient
{
    use ScrapHttp;

    public function __construct(
        private string $sitemapUrl = 'https://cubrecarterprotect.es/sitemap.xml',
        private string $productSuffix = '/a',
        private string $defaultCurrency = 'EUR',
    ) {}

    public function products(int $delayMs = 200, ?callable $onProgress = null): Generator
    {
        $urls = $this->productUrls();
        $total = count($urls);

        foreach ($urls as $i => $url) {
            $html = $this->get($url);
            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
            $product = $html !== null ? $this->parseProduct($html, $url) : null;

            if ($onProgress) {
                $onProgress($i + 1, $total);
            }
            if ($product !== null) {
                yield $product;
            }
        }
    }

    /** URL-e produktów z sitemap.xml (filtr po sufiksie sklepu). @return string[] */
    public function productUrls(): array
    {
        $xml = $this->get($this->sitemapUrl, acceptNotFound: true);   // sitemapa pod statusem 404 — patrz docblock
        if ($xml === null || ! str_contains($xml, '<urlset')) {       // 404 bez sitemapy = zwykła strona błędu
            return [];
        }

        preg_match_all('#<loc>(.*?)</loc>#', $xml, $m);
        $urls = [];
        foreach ($m[1] as $loc) {
            $loc = html_entity_decode(trim($loc));
            if (str_ends_with($loc, $this->productSuffix)) {
                $urls[] = $loc;
            }
        }

        return array_values(array_unique($urls));
    }

    /** Parser strony produktu: JSON-LD Product + dopięcie kodu artykułu z DOM. null = brak danych produktu. */
    public function parseProduct(string $html, string $url): ?array
    {
        $product = $this->jsonLdProduct($html);
        if ($product === null) {
            return null;
        }

        $offers = $product['offers'] ?? [];
        if (array_is_list($offers) && isset($offers[0])) {
            $offers = $offers[0];
        }

        $ean = $this->firstEan13($product['gtin13'] ?? null, $product['sku'] ?? null);
        $hn = $product['mpn'] ?? $this->productCode($html);
        $externalId = $ean ?: $hn;
        if (! $externalId) {
            return null;
        }

        return [
            'external_id' => (string) $externalId,
            'title' => (string) ($product['name'] ?? ''),
            'price' => isset($offers['price']) ? (float) $offers['price'] : null,
            'currency' => $offers['priceCurrency'] ?? $this->defaultCurrency,
            'herstellernummer' => $hn,
            'ean' => $ean,
            'url' => $offers['url'] ?? $url,
            'raw' => array_filter([
                'original_price' => $this->strikethrough($offers),
                'category' => $product['category'] ?? null,
            ], fn ($v) => $v !== null && $v !== ''),
        ];
    }

    /** Pierwszy blok JSON-LD typu Product. */
    private function jsonLdProduct(string $html): ?array
    {
        if (! preg_match_all('#<script[^>]*application/ld\+json[^>]*>(.*?)</script>#si', $html, $m)) {
            return null;
        }
        foreach ($m[1] as $json) {
            $d = json_decode(trim($json), true);
            if (! is_array($d)) {
                continue;
            }
            $type = $d['@type'] ?? null;
            if ($type === 'Product' || (is_array($type) && in_array('Product', $type, true))) {
                return $d;
            }
        }

        return null;
    }

    /** Pierwsza z podanych wartości wyglądająca na EAN13 (sku bywa kodem artykułu, nie EAN-em). */
    private function firstEan13(...$candidates): ?string
    {
        foreach ($candidates as $v) {
            $v = trim((string) $v);
            if (preg_match('/^\d{13}$/', $v)) {
                return $v;
            }
        }

        return null;
    }

    /** Kod artykułu z tabelki `<tr data-sku=""><td>Produktcode</td><td>30.146</td></tr>` (1 wiersz na stronie).
     *  Regex zamiast DOM — 1600 stron × ~330 kB parsowania to zbędny koszt; Crawler tylko jako zapasowe wyjście. */
    private function productCode(string $html): ?string
    {
        if (preg_match('#<tr[^>]*\sdata-sku=[^>]*>\s*<td[^>]*>.*?</td>\s*<td[^>]*>(.*?)</td>#si', $html, $m)) {
            $code = trim(html_entity_decode(strip_tags($m[1])));
            if ($code !== '') {
                return $code;
            }
        }

        $tr = (new Crawler($html))->filter('tr[data-sku] td');

        return $tr->count() >= 2 ? (trim($tr->eq(1)->text()) ?: null) : null;
    }

    /** Cena przekreślona (StrikethroughPrice) z priceSpecification. */
    private function strikethrough(array $offers): ?float
    {
        foreach ($offers['priceSpecification'] ?? [] as $spec) {
            if (($spec['priceType'] ?? '') === 'https://schema.org/StrikethroughPrice' && isset($spec['price'])) {
                return (float) $spec['price'];
            }
        }

        return null;
    }
}
