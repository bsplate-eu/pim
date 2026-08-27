<?php

namespace App\Services\Ebay;

use App\Models\Ebay\EbayActionLog;
use App\Models\Ebay\EbayOffer;
use App\Models\Scrap\EbaySettings;
use App\Services\Scrap\ProductMatcher;

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

        // Znacznik startu — po przebiegu wszystko starsze = eBay tego już nie pokazuje.
        $startedAt = now();

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

        // Aukcje, których ten przebieg NIE zobaczył, na eBay już nie istnieją. Bez tego PIM trzyma
        // je jako „Active" w nieskończoność: eBay odrzuca na nich każdą operację („Bereits beendete
        // Angebote…"), ich ceny wiszą jako wiecznie rozjechane, a na ekranie Wystawianie produkt
        // wygląda na wystawiony, więc nie da się go wystawić ponownie.
        // Guard $fetched > 0: pusty wynik oznacza problem z API, nie pusty katalog — inaczej jeden
        // nieudany przebieg oznaczyłby cały rynek jako zakończony.
        $ended = 0;
        if ($fetched > 0) {
            $ended = EbayOffer::query()
                ->where('marketplace', $marketplace)
                ->where('last_seen', '<', $startedAt)
                ->where(fn ($w) => $w->whereNull('listing_status')->orWhere('listing_status', '!=', 'Ended'))
                ->update(['listing_status' => 'Ended']);

            if ($ended > 0) {
                \Illuminate\Support\Facades\Log::info("eBay sync {$marketplace}: oznaczono {$ended} aukcji jako zakończone (nie wróciły z API).");
            }
        }

        $matched = $this->matchBySku($marketplace);

        return [
            'marketplace' => $marketplace,
            'fetched' => $fetched,
            'new' => $new,
            'ended' => $ended,
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
        // Próg: uzupełniaj gdy stan <= auto_restock_when (domyślnie 1). Warunek „== 0" był bezużyteczny —
        // PIM widzi świeży stan tylko w chwili syncu, a towar schodzi między syncami.
        $when = max(0, (int) ($this->settings->auto_restock_when ?? 1));
        $client = new EbaySellClient($this->settings, new EbayOAuthService($this->settings));

        $done = 0;
        EbayOffer::where('quantity', '<=', $when)
            ->where('quantity', '<', $to)   // tylko PODNOŚ — nigdy nie obniżaj istniejącego stanu
            ->where('listing_status', 'Active')
            ->chunkById(50, function ($offers) use ($client, $to, $context, &$done) {
                foreach ($offers as $o) {
                    $before = (int) $o->quantity;
                    try {
                        $client->reviseQuantity($o->item_id, (string) $o->sku, $to, $o->marketplace, (int) $o->quantity_sold);
                        $o->forceFill(['quantity' => $to])->save();
                        $this->logAction($o, EbayActionLog::ACTION_AUTO_RESTOCK, $context, EbayActionLog::STATUS_OK, ['qty_before' => $before, 'qty_after' => $to]);
                        usleep(300_000);
                        $done++;
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::warning("eBay auto-restock {$o->item_id}/{$o->sku}: " . $e->getMessage());
                        $this->logAction($o, EbayActionLog::ACTION_AUTO_RESTOCK, $context, EbayActionLog::STATUS_ERROR, ['qty_before' => $before, 'message' => $e->getMessage()]);
                    }
                }
            });

        return $done;
    }

    /** Automatyczna akcja „auto-przypisanie": nieprzypisane oferty → nasz produkt po SKU
     *  (ebay_offers.sku ↔ Product.product_code + tytuł oferty; wszystkie produkty, też wyłączone).
     *  Sam kod NIE wystarcza — bywa zduplikowany (13.121 = Mazda 3/6/Atenza/Axela/CX5, 18.201 = 21 aut),
     *  więc przy duplikacie rozstrzyga tytuł: ProductMatcher::pickForCode (ta sama logika co Argo Scope).
     *  NIE dotyka eBay (tylko mapowanie w bazie), więc działa też bez połączonego konta.
     *  Każde dopasowanie trafia do ebay_action_logs. $context = skąd wywołane. Zwraca liczbę przypisanych. */
    public function applyAutoAssign(string $context = EbayActionLog::CONTEXT_CRON): int
    {
        if (! $this->settings->auto_assign_enabled) {
            return 0;
        }
        $matcher = new ProductMatcher();
        $byCode = $matcher->candidatesByCode();

        $done = 0;
        EbayOffer::whereNull('product_id')
            ->whereNotNull('sku')
            ->where('sku', '!=', '')
            ->chunkById(500, function ($offers) use ($matcher, $byCode, $context, &$done) {
                foreach ($offers as $o) {
                    $pid = $matcher->pickForCode($byCode, $o->sku, $o->title)['id'];
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

    /** Auto-mapowanie oferta.sku + tytuł ↔ nasz produkt, w obrębie rynku.
     *  Używane w trakcie pobierania ofert (bez logowania — mechanika fetch). Zwraca liczbę dopasowanych.
     *  Przy zduplikowanym product_code rozstrzyga tytuł — patrz ProductMatcher::pickForCode. */
    private function matchBySku(string $marketplace): int
    {
        $matcher = new ProductMatcher();
        $byCode = $matcher->candidatesByCode();

        $matched = 0;
        EbayOffer::where('marketplace', $marketplace)
            ->whereNull('product_id')
            ->where('sku', '!=', '')
            ->chunkById(500, function ($offers) use ($matcher, $byCode, &$matched) {
                foreach ($offers as $o) {
                    $pid = $matcher->pickForCode($byCode, $o->sku, $o->title)['id'];
                    if ($pid) {
                        $o->forceFill(['product_id' => $pid, 'match_type' => 'auto'])->save();
                        $matched++;
                    }
                }
            });

        return $matched;
    }
}
