<?php

namespace App\Services\Ebay;

use App\Models\Ebay\EbayActionLog;
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

    /** Automatyczna akcja „auto-restock": aktywne oferty ze stanem 0 → ustaw docelowy (auto_restock_to) na eBay.
     *  Działa tylko gdy reguła włączona i konto połączone. Każda oferta (sukces/błąd) trafia do ebay_action_logs.
     *  $context = skąd wywołane (cron/manual/sync). Zwraca liczbę podniesionych ofert. */
    public function applyAutoRestock(string $context = EbayActionLog::CONTEXT_CRON): int
    {
        if (! $this->settings->auto_restock_enabled || ! $this->settings->isOauthConnected()) {
            return 0;
        }
        $to = max(1, (int) ($this->settings->auto_restock_to ?? 5));
        $client = new EbaySellClient($this->settings, new EbayOAuthService($this->settings));

        $done = 0;
        EbayOffer::where('quantity', 0)
            ->where('listing_status', 'Active')
            ->chunkById(50, function ($offers) use ($client, $to, $context, &$done) {
                foreach ($offers as $o) {
                    try {
                        $client->reviseQuantity($o->item_id, (string) $o->sku, $to, $o->marketplace, (int) $o->quantity_sold);
                        $o->forceFill(['quantity' => $to])->save();
                        $this->logAction($o, EbayActionLog::ACTION_AUTO_RESTOCK, $context, EbayActionLog::STATUS_OK, ['qty_before' => 0, 'qty_after' => $to]);
                        usleep(300_000);
                        $done++;
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::warning("eBay auto-restock {$o->item_id}/{$o->sku}: " . $e->getMessage());
                        $this->logAction($o, EbayActionLog::ACTION_AUTO_RESTOCK, $context, EbayActionLog::STATUS_ERROR, ['qty_before' => 0, 'message' => $e->getMessage()]);
                    }
                }
            });

        return $done;
    }

    /** Automatyczna akcja „auto-przypisanie": nieprzypisane oferty → nasz produkt po SKU
     *  (ebay_offers.sku ↔ Product.product_code, znormalizowane; wszystkie produkty, też wyłączone).
     *  NIE dotyka eBay (tylko mapowanie w bazie), więc działa też bez połączonego konta.
     *  Każde dopasowanie trafia do ebay_action_logs. $context = skąd wywołane. Zwraca liczbę przypisanych. */
    public function applyAutoAssign(string $context = EbayActionLog::CONTEXT_CRON): int
    {
        if (! $this->settings->auto_assign_enabled) {
            return 0;
        }
        $codes = $this->productCodeMap();

        $done = 0;
        EbayOffer::whereNull('product_id')
            ->whereNotNull('sku')
            ->where('sku', '!=', '')
            ->chunkById(500, function ($offers) use ($codes, $context, &$done) {
                foreach ($offers as $o) {
                    $pid = $codes[$this->norm($o->sku)] ?? null;
                    if (! $pid) {
                        continue;
                    }
                    try {
                        $o->forceFill(['product_id' => $pid, 'match_type' => 'auto'])->save();
                        $this->logAction($o, EbayActionLog::ACTION_AUTO_ASSIGN, $context, EbayActionLog::STATUS_OK);
                        $done++;
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::warning("eBay auto-assign {$o->item_id}/{$o->sku}: " . $e->getMessage());
                        $this->logAction($o, EbayActionLog::ACTION_AUTO_ASSIGN, $context, EbayActionLog::STATUS_ERROR, ['message' => $e->getMessage()]);
                    }
                }
            });

        return $done;
    }

    /** Zapis pojedynczego zdarzenia automatycznej akcji do dziennika (ebay_action_logs).
     *  $extra nadpisuje/dokłada pola (qty_before/qty_after dla restocku, message dla błędu). */
    private function logAction(EbayOffer $o, string $action, string $context, string $status, array $extra = []): void
    {
        if (isset($extra['message']) && is_string($extra['message'])) {
            $extra['message'] = mb_substr($extra['message'], 0, 250);
        }
        EbayActionLog::create(array_merge([
            'action' => $action,
            'context' => $context,
            'status' => $status,
            'marketplace' => $o->marketplace,
            'item_id' => $o->item_id,
            'sku' => $o->sku,
            'title' => $o->title,
            'listing_url' => $o->listing_url,
            'product_id' => $o->product_id,
        ], $extra));
    }

    /** Auto-mapowanie oferta.sku ↔ Product.product_code (znormalizowane) w obrębie rynku.
     *  Używane w trakcie pobierania ofert (bez logowania — mechanika fetch). Zwraca liczbę dopasowanych. */
    private function matchBySku(string $marketplace): int
    {
        $codes = $this->productCodeMap();

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

    /** Mapa: znormalizowany product_code → product_id (wszystkie produkty z kodem; pierwszy wygrywa). */
    private function productCodeMap(): array
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

        return $codes;
    }

    /** Normalizacja klucza (jak ProductMatcher): bez cudzysłowów, trim, wielkie litery. */
    private function norm(?string $v): string
    {
        return strtoupper(trim(str_replace(['"', "'"], '', (string) $v)));
    }
}
