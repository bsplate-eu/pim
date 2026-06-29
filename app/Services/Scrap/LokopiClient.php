<?php

namespace App\Services\Scrap;

use Generator;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Driver sklepów na platformie „lokopi WEB" (Scut Protection):
 *   Niemcy  — stahl-unterfahrschutz.eu (EUR)
 *   Węgry   — motorvedolemezek.com     (HUF)
 *
 * Sitemap XML → URL-e produktów (priorytet 1.0; kategorie 0.6/0.8 odpadają; strony statyczne → parser null).
 * Selektory wspólne (zwalidowane na żywo 2026-06-25), waluta z meta[itemprop=priceCurrency]:
 *   external_id      ← .add-to-cart[data-product-id]   (UNIKALNY; kod artykułu NIE jest unikalny)
 *   title            ← h1[itemprop=name]
 *   price (brutto)   ← span[itemprop=price]
 *   herstellernummer ← .cod-prod (regex \d{2}.\d{3,4}[ALU]) — językowo-niezależne (ArtikelNr/Termékkód)
 *   ean              ← span[itemprop=gtin13]
 */
class LokopiClient implements ShopClient
{
    use ScrapHttp;

    public function __construct(
        private string $sitemapUrl,
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

    /** URL-e stron produktów z sitemapy: priorytet 1.0. @return string[] */
    public function productUrls(): array
    {
        $xml = $this->get($this->sitemapUrl);
        if ($xml === null) {
            return [];
        }

        preg_match_all('#<url>(.*?)</url>#s', $xml, $blocks);
        $urls = [];
        foreach ($blocks[1] as $b) {
            if (! preg_match('#<loc>(.*?)</loc>#', $b, $ml)) {
                continue;
            }
            preg_match('#<priority>(.*?)</priority>#', $b, $mp);
            if ((isset($mp[1]) ? (float) $mp[1] : 0.0) >= 1.0) {
                $urls[] = html_entity_decode(trim($ml[1]));
            }
        }

        return array_values(array_unique($urls));
    }

    /** Parser strony produktu. null = strona nie-produktowa (brak data-product-id). */
    public function parseProduct(string $html, string $url): ?array
    {
        $c = new Crawler($html, $url);

        $externalId = $this->attr($c, '.add-to-cart', 'data-product-id')
            ?: $this->attr($c, '#addToCartButton', 'data-product-id');
        if ($externalId === null || $externalId === '') {
            return null;
        }

        // Kod artykułu z .cod-prod (ArtikelNr / Termékkód / …) — łapiemy sam kod, niezależnie od języka etykiety.
        $hn = null;
        $codBlock = $this->text($c, '.cod-prod');
        if ($codBlock !== null && preg_match('/\b\d{2}\.\d{3,4}(?:[A-Za-z]{1,4})?\b/', $codBlock, $m)) {
            $hn = $m[0];
        }

        return [
            'external_id' => (string) $externalId,
            'title' => trim($this->text($c, 'h1[itemprop=name]') ?? $this->attr($c, '.add-to-cart', 'data-name') ?? ''),
            'price' => $this->parsePrice($this->text($c, 'span[itemprop=price]')),  // brutto
            'currency' => $this->attr($c, 'meta[itemprop=priceCurrency]', 'content') ?: $this->defaultCurrency,
            'herstellernummer' => $hn,
            'ean' => $this->text($c, 'span[itemprop=gtin13]') ?: null,
            'url' => $url,
            'raw' => array_filter([
                'original_price' => $this->parsePrice($this->text($c, 'span.discount')),
                'brand' => $this->attr($c, '.add-to-cart', 'data-brand'),
                'variant' => $this->attr($c, '.add-to-cart', 'data-variant'),
            ], fn ($v) => $v !== null && $v !== ''),
        ];
    }

    /** "169" / "57100 " / "1.234,56" → float|null. */
    private function parsePrice(?string $raw): ?float
    {
        if ($raw === null) {
            return null;
        }
        $s = preg_replace('/[^0-9.,]/', '', trim($raw));
        if ($s === '') {
            return null;
        }
        if (str_contains($s, ',') && str_contains($s, '.')) {
            $s = strrpos($s, ',') > strrpos($s, '.') ? str_replace('.', '', $s) : str_replace(',', '', $s);
        }
        $s = str_replace(',', '.', $s);

        return is_numeric($s) ? (float) $s : null;
    }

    private function text(Crawler $c, string $selector): ?string
    {
        $n = $c->filter($selector);

        return $n->count() ? trim($n->first()->text()) : null;
    }

    private function attr(Crawler $c, string $selector, string $attribute): ?string
    {
        $n = $c->filter($selector);

        return $n->count() ? $n->first()->attr($attribute) : null;
    }
}
