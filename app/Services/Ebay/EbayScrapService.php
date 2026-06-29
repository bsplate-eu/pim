<?php

namespace App\Services\Ebay;

use App\Models\Pricelist;
use App\Models\PricelistProduct;
use App\Models\Product;
use App\Models\Scrap\EbaySettings;
use App\Models\Scrap\ScrapChange;
use App\Models\Scrap\ScrapProduct;

/**
 * Orchestracja monitoringu eBay (Argo Scope).
 *  - sync():     szybki — tylko nazwa+cena z listy (sekundy). Podgląd.
 *  - fullSync(): pełny pomiar — aspekty (HN/EAN) + diff vs poprzedni stan + aktualizacja cennika.
 *                Wolne (~kilka min dla ~1500) — uruchamiać z komendy/joba, NIE z HTTP.
 *
 * Cennik: ceny eBay → „Ebay - Cennik" (slug blacksteelplate-de-kopia), match
 * herstellernummer ↔ Product.product_code (wzorzec App\Services\StahlService).
 */
class EbayScrapService
{
    private const PRICELIST_SLUG = 'blacksteelplate-de-kopia'; // „Ebay - Cennik"

    public function __construct(
        private EbayBrowseClient $client,
        private EbaySettings $settings,
    ) {}

    public static function fromSettings(EbaySettings $settings): self
    {
        return new self(
            new EbayBrowseClient($settings->client_id, $settings->client_secret, $settings->marketplace ?: 'EBAY_DE'),
            $settings,
        );
    }

    /** Szybki sync — tylko nazwa+cena z listy (bez aspektów). @return array{fetched:int,new:int,updated:int} */
    public function sync(): array
    {
        $seller = $this->settings->seller;
        $offers = $this->client->searchSeller($seller, $this->settings->keyword ?: 'Unterfahrschutz');
        $now = now();
        $new = 0;
        $updated = 0;

        foreach ($offers as $o) {
            $rec = ScrapProduct::firstOrNew(['source' => 'ebay', 'external_id' => $o['external_id']]);
            $exists = $rec->exists;
            if (! $exists) {
                $rec->first_seen = $now;
            }
            $rec->fill([
                'seller' => $seller,
                'title' => $o['title'],
                'price' => $o['price'],
                'currency' => $o['currency'] ?: 'EUR',
                'url' => $o['url'],
                'last_seen' => $now,
                'is_active' => true,
            ])->save();
            $exists ? $updated++ : $new++;
        }

        $this->settings->forceFill(['last_sync_at' => $now, 'last_sync_count' => count($offers)])->save();

        return ['fetched' => count($offers), 'new' => $new, 'updated' => $updated];
    }

    /**
     * Pełny pomiar: aspekty (HN/EAN) + diff + cennik + statystyki.
     * @return array{fetched:int,new:int,removed:int,price_up:int,price_down:int}
     */
    public function fullSync(): array
    {
        $seller = $this->settings->seller;
        $offers = $this->client->searchSeller($seller, $this->settings->keyword ?: 'Unterfahrschutz');
        $now = now();

        $prev = ScrapProduct::where('source', 'ebay')
            ->get(['id', 'external_id', 'price', 'is_active', 'first_seen'])
            ->keyBy('external_id');
        $prevActive = $prev->where('is_active', true)->count();

        $new = 0;
        $priceUp = 0;
        $priceDown = 0;
        $seen = [];

        foreach ($offers as $o) {
            [$hn, $ean] = $this->client->itemAspects($o['external_id']);
            usleep(120000); // grzecznie wobec limitu API

            $newPrice = $o['price'] !== null ? (float) $o['price'] : null;
            $existing = $prev->get($o['external_id']);

            if (! $existing) {
                $new++;
                $this->recordChange('new', $o, $hn, null, $newPrice, $now);
            } elseif ($newPrice !== null && $existing->price !== null && (float) $existing->price != $newPrice) {
                if ($newPrice > (float) $existing->price) {
                    $priceUp++;
                    $this->recordChange('price_up', $o, $hn, (float) $existing->price, $newPrice, $now);
                } else {
                    $priceDown++;
                    $this->recordChange('price_down', $o, $hn, (float) $existing->price, $newPrice, $now);
                }
            }

            $rec = ScrapProduct::firstOrNew(['source' => 'ebay', 'external_id' => $o['external_id']]);
            if (! $rec->exists) {
                $rec->first_seen = $now;
            }
            $rec->fill([
                'seller' => $seller,
                'title' => $o['title'],
                'price' => $newPrice,
                'currency' => $o['currency'] ?: 'EUR',
                'herstellernummer' => $hn,
                'ean' => $ean,
                'url' => $o['url'],
                'last_seen' => $now,
                'is_active' => true,
            ])->save();
            $seen[$o['external_id']] = true;
        }

        // Wycofane: były aktywne, brak w bieżącym pomiarze
        $removed = 0;
        foreach ($prev as $extId => $p) {
            if (! isset($seen[$extId]) && $p->is_active) {
                ScrapProduct::where('id', $p->id)->update(['is_active' => false]);
                $this->recordChange('removed', ['external_id' => $extId, 'title' => null], null, (float) $p->price, null, $now);
                $removed++;
            }
        }

        $this->updatePricelist();

        $this->settings->forceFill([
            'last_sync_at' => $now,
            'last_sync_count' => count($offers),
            'prev_offer_count' => $prevActive,
            'last_new_count' => $new,
            'last_removed_count' => $removed,
            'last_price_up' => $priceUp,
            'last_price_down' => $priceDown,
        ])->save();

        return ['fetched' => count($offers), 'new' => $new, 'removed' => $removed, 'price_up' => $priceUp, 'price_down' => $priceDown];
    }

    /**
     * Uzupełnia HN/EAN dla ofert, które przy pełnym pomiarze dostały pusto (rate limit eBay).
     * Wolniejsze tempo niż fullSync — mniej szans na ponowny limit.
     * @return array{checked:int,filled:int}
     */
    public function fillMissingAspects(): array
    {
        $missing = ScrapProduct::where('source', 'ebay')
            ->where('is_active', true)
            ->whereNull('herstellernummer')
            ->get(['id', 'external_id']);

        $filled = 0;
        foreach ($missing as $p) {
            [$hn, $ean] = $this->client->itemAspects($p->external_id);
            usleep(300000); // 0.3s — łagodniej dla limitu
            if ($hn || $ean) {
                $p->herstellernummer = $hn;
                $p->ean = $ean;
                $p->save();
                $filled++;
            }
        }

        if ($filled > 0) {
            $this->updatePricelist();
        }

        return ['checked' => $missing->count(), 'filled' => $filled];
    }

    private function recordChange(string $type, array $o, ?string $hn, ?float $old, ?float $new, $at): void
    {
        ScrapChange::create([
            'source' => 'ebay',
            'type' => $type,
            'external_id' => $o['external_id'],
            'title' => $o['title'] ?? null,
            'herstellernummer' => $hn,
            'old_price' => $old,
            'new_price' => $new,
            'detected_at' => $at,
        ]);
    }

    /** Wpina aktualne ceny eBay do cennika „Ebay - Cennik" po product_code ↔ herstellernummer.
     *  eBay = brutto, cennik = netto → ceny zapisujemy po odjęciu VAT (compare_vat). */
    private function updatePricelist(): void
    {
        $pricelist = Pricelist::where('slug', self::PRICELIST_SLUG)->first();
        if (! $pricelist) {
            return;
        }

        $priceByHn = ScrapProduct::where('source', 'ebay')
            ->where('is_active', true)
            ->whereNotNull('herstellernummer')
            ->whereNotNull('price')
            ->pluck('price', 'herstellernummer');

        if ($priceByHn->isEmpty()) {
            return;
        }

        $vat = (float) ($this->settings->compare_vat ?? 0);

        $rows = [];
        Product::whereNotNull('product_code')
            ->select(['id', 'product_code'])
            ->chunk(500, function ($chunk) use (&$rows, $priceByHn, $pricelist, $vat) {
                foreach ($chunk as $p) {
                    if (isset($priceByHn[$p->product_code])) {
                        $gross = (float) $priceByHn[$p->product_code]; // eBay brutto
                        $rows[] = [
                            'pricelist_id' => $pricelist->id,
                            'product_id' => $p->id,
                            'price' => $vat > 0 ? round($gross / (1 + $vat / 100), 2) : round($gross, 2),
                        ];
                    }
                }
            });

        if (! empty($rows)) {
            PricelistProduct::upsert($rows, ['pricelist_id', 'product_id'], ['price']);
        }
    }
}
