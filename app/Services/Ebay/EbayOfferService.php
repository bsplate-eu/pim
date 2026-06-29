<?php

namespace App\Services\Ebay;

use App\Models\Ebay\EbayOffer;
use App\Models\Product;
use App\Models\Scrap\EbaySettings;

/**
 * Pobieranie WŁASNYCH ofert eBay (Sell/Trading) → tabela ebay_offers + auto-mapowanie po SKU.
 * Wzorzec: App\Services\Ebay\EbayScrapService (monitoring), ale tu nasze aukcje.
 */
class EbayOfferService
{
    public function __construct(private EbaySettings $settings) {}

    public static function fromSettings(EbaySettings $settings): self
    {
        return new self($settings);
    }

    /** Pobierz wszystkie aktywne oferty (paginacja) danego rynku → upsert + auto-match SKU. */
    public function syncActiveListings(?string $marketplace = null): array
    {
        $marketplace = strtoupper($marketplace ?: ($this->settings->marketplace ?: 'EBAY_DE'));
        $client = new EbaySellClient($this->settings, new EbayOAuthService($this->settings));

        $page = 1;
        $totalPages = 1;
        $fetched = 0;
        $new = 0;

        do {
            $res = $client->activeListingsPage($marketplace, $page, 100);
            $totalPages = max(1, (int) $res['total_pages']);

            foreach ($res['items'] as $row) {
                $offer = EbayOffer::firstOrNew([
                    'item_id' => $row['item_id'],
                    'sku' => $row['sku'],
                    'marketplace' => $row['marketplace'],
                ]);
                if (! $offer->exists) {
                    $offer->first_seen = now();
                    $new++;
                }
                $offer->fill($row);
                $offer->last_seen = now();
                $offer->save();
                $fetched++;
            }

            $page++;
        } while ($page <= $totalPages);

        $matched = $this->matchBySku($marketplace);

        return [
            'marketplace' => $marketplace,
            'fetched' => $fetched,
            'new' => $new,
            'pages' => $totalPages,
            'matched' => $matched,
        ];
    }

    /** Auto-mapowanie oferta.sku ↔ Product.product_code (znormalizowane). Zwraca liczbę dopasowanych. */
    private function matchBySku(string $marketplace): int
    {
        $codes = [];
        Product::whereNotNull('product_code')->where('product_code', '!=', '')
            ->select(['id', 'product_code'])
            ->chunk(1000, function ($chunk) use (&$codes) {
                foreach ($chunk as $p) {
                    $k = $this->norm($p->product_code);
                    if ($k !== '' && ! isset($codes[$k])) {
                        $codes[$k] = $p->id;
                    }
                }
            });

        $matched = 0;
        EbayOffer::where('marketplace', $marketplace)
            ->whereNull('product_id')
            ->where('sku', '!=', '')
            ->chunkById(500, function ($offers) use ($codes, &$matched) {
                foreach ($offers as $o) {
                    $pid = $codes[$this->norm($o->sku)] ?? null;
                    if ($pid) {
                        $o->forceFill(['product_id' => $pid, 'match_type' => 'auto'])->save();
                        $matched++;
                    }
                }
            });

        return $matched;
    }

    /** Normalizacja klucza (jak ProductMatcher): bez cudzysłowów, trim, wielkie litery. */
    private function norm(?string $v): string
    {
        return strtoupper(trim(str_replace(['"', "'"], '', (string) $v)));
    }
}
