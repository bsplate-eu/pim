<?php

namespace App\Services\Ebay;

use App\Models\Pricelist;
use App\Models\PricelistProduct;
use App\Models\Product;
use App\Models\Scrap\EbaySettings;
use App\Models\Scrap\ScrapChange;
use App\Models\Scrap\ScrapProduct;
use App\Models\Scrap\ScrapSource;

/**
 * Orchestracja monitoringu eBay (Argo Scope) — PER RYNEK.
 *
 * Konkurent (scutprotectionsrl) prowadzi OSOBNY katalog na każdym rynku: inny język tytułów,
 * inne słowa-zalążki, własne ceny i waluta (DE/FR/IT/ES = EUR, UK = GBP, CH = CHF). Dlatego
 * każdy rynek to osobny `source` w scrap_products (= tab w Scope) i osobny pomiar.
 *
 *  - sync():     szybki — tylko nazwa+cena z listy (sekundy). Podgląd.
 *  - fullSync(): pełny pomiar — aspekty (HN/EAN) + diff vs poprzedni stan + statystyki kanału.
 *                Wolne (~kilka min na rynek) — uruchamiać z komendy/joba, NIE z HTTP.
 *
 * Uwaga: klucz 'ebay' = rynek DE (zachowany dla zgodności z istniejącymi danymi i konfiguracją).
 */
class EbayScrapService
{
    /**
     * Rynki eBay konkurenta. Klucz = `source` (tab w Scope). Słowa-zalążki w języku rynku
     * — bez tego Browse API zwraca 0 (tytuły są lokalne). Zweryfikowane sondą na koncie sprzedawcy.
     *
     * @var array<string,array{label:string,marketplace:string,currency:string,keywords:list<string>}>
     */
    public const MARKETS = [
        'ebay'    => ['label' => 'eBay.de',    'marketplace' => 'EBAY_DE', 'currency' => 'EUR', 'keywords' => ['Unterfahrschutz', 'Getriebeschutz', 'Motorschutz', 'Differentialschutz']],
        'ebay_fr' => ['label' => 'eBay.fr',    'marketplace' => 'EBAY_FR', 'currency' => 'EUR', 'keywords' => ['protection sous moteur', 'blindage moteur', 'sabot moteur', 'protection boite de vitesses']],
        'ebay_it' => ['label' => 'eBay.it',    'marketplace' => 'EBAY_IT', 'currency' => 'EUR', 'keywords' => ['paramotore', 'protezione sottoscocca', 'piastra paramotore', 'protezione cambio']],
        'ebay_es' => ['label' => 'eBay.es',    'marketplace' => 'EBAY_ES', 'currency' => 'EUR', 'keywords' => ['cubre carter', 'protector de carter', 'protector de motor', 'protector caja de cambios']],
        'ebay_gb' => ['label' => 'eBay.co.uk', 'marketplace' => 'EBAY_GB', 'currency' => 'GBP', 'keywords' => ['skid plate', 'sump guard', 'underbody protection', 'gearbox guard']],
        'ebay_ch' => ['label' => 'eBay.ch',    'marketplace' => 'EBAY_CH', 'currency' => 'CHF', 'keywords' => ['Unterfahrschutz', 'Getriebeschutz', 'Motorschutz']],
    ];

    private const PRICELIST_SLUG = 'blacksteelplate-de-kopia'; // „Ebay - Cennik" (auto-push tylko dla rynku DE)

    /**
     * @param  array{label:string,marketplace:string,currency:string,keywords:list<string>}  $market
     */
    public function __construct(
        private EbayBrowseClient $client,
        private EbaySettings $settings,
        private string $source,
        private array $market,
    ) {}

    public static function isMarket(string $source): bool
    {
        return isset(self::MARKETS[$source]);
    }

    /** @return list<string> klucze rynków eBay (= source kanałów w Scope) */
    public static function marketKeys(): array
    {
        return array_keys(self::MARKETS);
    }

    public static function forMarket(EbaySettings $settings, string $source): self
    {
        $market = self::MARKETS[$source] ?? throw new \InvalidArgumentException("Nieznany rynek eBay: {$source}");

        return new self(
            new EbayBrowseClient($settings->client_id, $settings->client_secret, $market['marketplace']),
            $settings,
            $source,
            $market,
        );
    }

    /** Wsteczna zgodność: domyślny rynek DE (klucz 'ebay'). */
    public static function fromSettings(EbaySettings $settings): self
    {
        return self::forMarket($settings, 'ebay');
    }

    /** Szybki sync — tylko nazwa+cena z listy (bez aspektów). @return array{fetched:int,new:int,updated:int} */
    public function sync(): array
    {
        $seller = $this->settings->seller;
        $offers = $this->client->searchSeller($seller, $this->market['keywords']);
        $now = now();
        $new = 0;
        $updated = 0;

        foreach ($offers as $o) {
            $rec = ScrapProduct::firstOrNew(['source' => $this->source, 'external_id' => $o['external_id']]);
            $exists = $rec->exists;
            if (! $exists) {
                $rec->first_seen = $now;
            }
            $rec->fill([
                'seller' => $seller,
                'title' => $o['title'],
                'price' => $o['price'],
                'currency' => $o['currency'] ?: $this->market['currency'],
                'url' => $o['url'],
                'last_seen' => $now,
                'is_active' => true,
            ])->save();
            $exists ? $updated++ : $new++;
        }

        ScrapSource::updateOrCreate(['source' => $this->source], [
            'label' => $this->market['label'],
            'last_sync_at' => $now,
            'last_sync_count' => count($offers),
        ]);

        return ['fetched' => count($offers), 'new' => $new, 'updated' => $updated];
    }

    /**
     * Pełny pomiar rynku: aspekty (HN/EAN) + diff + statystyki kanału (scrap_sources).
     * @return array{fetched:int,new:int,removed:int,price_up:int,price_down:int}
     */
    public function fullSync(): array
    {
        $started = now();
        $seller = $this->settings->seller;
        $offers = $this->client->searchSeller($seller, $this->market['keywords']);
        $now = now();

        $prev = ScrapProduct::where('source', $this->source)
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

            $rec = ScrapProduct::firstOrNew(['source' => $this->source, 'external_id' => $o['external_id']]);
            if (! $rec->exists) {
                $rec->first_seen = $now;
            }
            $rec->fill([
                'seller' => $seller,
                'title' => $o['title'],
                'price' => $newPrice,
                'currency' => $o['currency'] ?: $this->market['currency'],
                'herstellernummer' => $hn,
                'ean' => $ean,
                'url' => $o['url'],
                'last_seen' => $now,
                'is_active' => true,
            ])->save();
            $seen[$o['external_id']] = true;
        }

        // Wycofane: były aktywne, brak w bieżącym pomiarze. Tylko gdy pomiar wygląda kompletnie
        // (≥50% poprzedniego stanu) — nie kasujemy katalogu po słabym crawlu / limicie API.
        $removed = 0;
        $looksComplete = $prevActive === 0 || count($seen) >= (int) ($prevActive * 0.5);
        if ($looksComplete) {
            foreach ($prev as $extId => $p) {
                if (! isset($seen[$extId]) && $p->is_active) {
                    ScrapProduct::where('id', $p->id)->update(['is_active' => false]);
                    $this->recordChange('removed', ['external_id' => $extId, 'title' => null], null, (float) $p->price, null, $now);
                    $removed++;
                }
            }
        }

        // Auto-push do „Ebay - Cennik" zachowany TYLKO dla rynku DE (zgodność wsteczna).
        // Pozostałe rynki używają konfiguracji per-kanał (cennik docelowy + „Aktualizuj cennik").
        if ($this->source === 'ebay') {
            $this->updatePricelist();
        }

        ScrapSource::updateOrCreate(['source' => $this->source], [
            'label' => $this->market['label'],
            'last_sync_at' => $now,
            'last_sync_count' => count($offers),
            'prev_offer_count' => $prevActive,
            'last_new_count' => $new,
            'last_removed_count' => $removed,
            'last_price_up' => $priceUp,
            'last_price_down' => $priceDown,
            'last_status' => 'ok',
            'last_duration_s' => now()->diffInSeconds($started),
        ]);

        return ['fetched' => count($offers), 'new' => $new, 'removed' => $removed, 'price_up' => $priceUp, 'price_down' => $priceDown];
    }

    /**
     * Uzupełnia HN/EAN dla ofert rynku, które przy pełnym pomiarze dostały pusto (rate limit eBay).
     * @return array{checked:int,filled:int}
     */
    public function fillMissingAspects(): array
    {
        $missing = ScrapProduct::where('source', $this->source)
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

        if ($filled > 0 && $this->source === 'ebay') {
            $this->updatePricelist();
        }

        return ['checked' => $missing->count(), 'filled' => $filled];
    }

    private function recordChange(string $type, array $o, ?string $hn, ?float $old, ?float $new, $at): void
    {
        ScrapChange::create([
            'source' => $this->source,
            'type' => $type,
            'external_id' => $o['external_id'],
            'title' => $o['title'] ?? null,
            'herstellernummer' => $hn,
            'old_price' => $old,
            'new_price' => $new,
            'detected_at' => $at,
        ]);
    }

    /** Wpina aktualne ceny eBay DE do cennika „Ebay - Cennik" po product_code ↔ herstellernummer.
     *  eBay = brutto, cennik = netto → ceny zapisujemy po odjęciu VAT (compare_vat). Tylko rynek DE. */
    private function updatePricelist(): void
    {
        $pricelist = Pricelist::where('slug', self::PRICELIST_SLUG)->first();
        if (! $pricelist) {
            return;
        }

        $priceByHn = ScrapProduct::where('source', $this->source)
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
