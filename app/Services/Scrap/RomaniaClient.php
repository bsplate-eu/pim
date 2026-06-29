<?php

namespace App\Services\Scrap;

use Generator;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Driver sklepu Rumunia — scut-motor.ro (RON). Inna platforma niż lokopi: SPA z client-side routingiem,
 * ale strony marek /{marka} są SSR i zawierają KOMPLET produktów marki. (NIE /search — ma limit 50/markę.)
 * Enumeracja: lista marek → strona /{marka} → karty produktów (dedup po item_id).
 *
 * Karta produktu = <div class="single_product" data-gtm-payload="{item_id,item_sku,item_name,price,discount,…}">.
 * Cały komplet danych jest w gtm-payload — bez wchodzenia w stronę produktu (~50 zapytań zamiast ~1550).
 *   external_id ← item_id · herstellernummer ← item_sku (np. 30.142) · title ← item_name · price ← price (RON)
 */
class RomaniaClient implements ShopClient
{
    use ScrapHttp;

    /** Strony statyczne — odfiltrowane przy wykrywaniu marek z nawigacji. */
    private const STATIC_SLUGS = [
        'cart', 'contact', 'cum-cumpar', 'cum-platesc', 'despre-noi', 'feedback',
        'formular-return', 'livrare-si-retur', 'protectia-datelor', 'termeni-si-conditii', 'search', 'shop',
    ];

    /** Bazowa (kompletna) lista marek Scut Protection — łączona z markami wykrytymi z nawigacji.
     *  Nadmiar jest nieszkodliwy (puste wyniki są pomijane); chodzi o pełne pokrycie katalogu. */
    private const BASE_BRANDS = [
        'alfa-romeo', 'audi', 'baic', 'bmw', 'byd', 'chevrolet', 'citroen', 'cupra', 'dacia', 'daewoo',
        'daihatsu', 'dr', 'evo', 'fiat', 'ford', 'honda', 'hyundai', 'isuzu', 'iveco', 'jac', 'jeep',
        'kia', 'lancia', 'land-rover', 'lexus', 'man', 'maxus', 'mazda', 'mercedes-benz', 'mercedes',
        'mini', 'mitsubishi', 'nissan', 'opel', 'peugeot', 'porsche', 'renault', 'seat', 'skoda', 'smart',
        'ssangyong', 'subaru', 'suzuki', 'tesla', 'toyota', 'volkswagen', 'vw', 'volvo',
    ];

    public function __construct(
        private string $base = 'https://www.scut-motor.ro',
    ) {}

    public function products(int $delayMs = 200, ?callable $onProgress = null): Generator
    {
        $brands = $this->brandSlugs();
        $total = count($brands);
        $seen = [];

        foreach ($brands as $i => $brand) {
            $html = $this->get($this->base . '/' . urlencode($brand));   // strona marki = pełna lista (bez limitu)
            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
            if ($onProgress) {
                $onProgress($i + 1, $total);
            }
            if ($html === null) {
                continue;
            }

            foreach ($this->parseCards($html) as $product) {
                $id = $product['external_id'];
                if ($id === '' || isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                yield $product;
            }
        }
    }

    /** Karty .single_product z osadzonym data-gtm-payload. @return array[] */
    public function parseCards(string $html): array
    {
        $c = new Crawler($html, $this->base);
        $out = [];

        $c->filter('.single_product[data-gtm-payload]')->each(function (Crawler $card) use (&$out) {
            $raw = $card->attr('data-gtm-payload');
            $p = json_decode((string) $raw, true);
            if (! is_array($p)) {
                $p = json_decode(html_entity_decode((string) $raw, ENT_QUOTES), true);
            }
            if (! is_array($p) || empty($p['item_id'])) {
                return;
            }

            // URL produktu = pierwszy nie-kotwicowy link w karcie.
            $url = null;
            foreach ($card->filter('a') as $a) {
                $href = $a->getAttribute('href');
                if ($href && ! str_starts_with($href, '#')) {
                    $url = str_starts_with($href, 'http') ? $href : $this->base . '/' . ltrim($href, '/');
                    break;
                }
            }

            $ean = null;
            $g = $card->filter('[itemprop=gtin13]');
            if ($g->count()) {
                $ean = trim($g->first()->text()) ?: null;
            }

            $price = isset($p['price']) ? (float) $p['price'] : null;
            $discount = (float) ($p['discount'] ?? 0);

            $out[] = [
                'external_id' => (string) $p['item_id'],
                'title' => (string) ($p['item_name'] ?? ''),
                'price' => $price,
                'currency' => 'RON',
                'herstellernummer' => ! empty($p['item_sku']) ? (string) $p['item_sku'] : null,
                'ean' => $ean,
                'url' => $url,
                'raw' => array_filter([
                    'original_price' => ($discount > 0 && $price) ? $price + $discount : null,
                    'discount' => $discount ?: null,
                    'years' => $p['item_category3'] ?? null,
                ], fn ($v) => $v !== null && $v !== ''),
            ];
        });

        return $out;
    }

    /** Lista marek do enumeracji = baza ∪ marki wykryte z nawigacji (pełne pokrycie + nowości). */
    private function brandSlugs(): array
    {
        $html = $this->get($this->base . '/audi');   // strona marki ma w nawigacji pełne menu marek
        $dynamic = $html !== null ? $this->extractBrands($html) : [];

        return array_values(array_unique(array_merge(self::BASE_BRANDS, $dynamic)));
    }

    /** Marki z nawigacji: jednoczłonowe slugi bez cyfr, nie-produkty, nie-statyczne.
     *  Wieloczłonowe (modele typu vw-golf oraz marki typu alfa-romeo) pomijamy — marki są w BASE_BRANDS. */
    private function extractBrands(string $html): array
    {
        preg_match_all('#href="(?:https://www\.scut-motor\.ro/)?([a-z][a-z0-9-]*)"#', $html, $m);
        $out = [];
        foreach (array_unique($m[1]) as $slug) {
            if (preg_match('/\d/', $slug) || str_contains($slug, '-')) {   // roczniki / modele / wieloczłonowe
                continue;
            }
            if (str_starts_with($slug, 'scut') || str_starts_with($slug, 'aluminiu')) {
                continue;
            }
            if (in_array($slug, self::STATIC_SLUGS, true) || strlen($slug) < 2 || strlen($slug) > 20) {
                continue;
            }
            $out[] = $slug;
        }

        return array_values(array_unique($out));
    }
}
