<?php

namespace App\Services\Scrap;

use Generator;

/**
 * Driver sklepu Hiszpania — cubrecarterprotect.es (EUR). Platforma Joomla „tshop" (SSR), inna niż lokopi.
 * sitemap.xml → URL-e produktów (sufiks /a; kategorie = /c, strony = /paginas…).
 * Strona produktu ma JSON-LD Product — najpewniejsze źródło (bez kruchych selektorów):
 *   mpn = kod artykułu (30.146) · gtin13 = EAN · offers.price/priceCurrency · StrikethroughPrice = cena sprzed rabatu.
 *   external_id = gtin13 (EAN unikalny per produkt; kod/mpn NIE jest unikalny — 1 kod = kilka modeli).
 *
 * robots.txt: User-agent:* → Allow:/ (blokady Disallow dotyczą botów AI: ClaudeBot/GPTBot/Amazonbot — nie nas;
 * dla zwykłych agentów off-limit jest tylko /ajax_functions). Scrape do monitoringu cen = w zgodzie z polityką.
 */
class SpainClient implements ShopClient
{
    use ScrapHttp;

    public function __construct(
        private string $base = 'https://cubrecarterprotect.es',
        private string $sitemapUrl = 'https://cubrecarterprotect.es/sitemap.xml',
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

    /** URL-e produktów z sitemap.xml: sufiks /a (kategorie = /c). @return string[] */
    public function productUrls(): array
    {
        $xml = $this->get($this->sitemapUrl);
        if ($xml === null) {
            return [];
        }

        preg_match_all('#<loc>(.*?)</loc>#', $xml, $m);
        $urls = [];
        foreach ($m[1] as $loc) {
            $loc = html_entity_decode(trim($loc));
            if (str_ends_with($loc, '/a')) {
                $urls[] = $loc;
            }
        }

        return array_values(array_unique($urls));
    }

    /** Parser strony produktu: JSON-LD Product. null = brak danych produktu. */
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

        $ean = $product['gtin13'] ?? null;
        $externalId = $ean ?: ($product['mpn'] ?? null);
        if (! $externalId) {
            return null;
        }

        return [
            'external_id' => (string) $externalId,
            'title' => (string) ($product['name'] ?? ''),
            'price' => isset($offers['price']) ? (float) $offers['price'] : null,
            'currency' => $offers['priceCurrency'] ?? 'EUR',
            'herstellernummer' => $product['mpn'] ?? null,
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
