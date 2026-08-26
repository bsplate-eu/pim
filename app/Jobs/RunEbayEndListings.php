<?php

namespace App\Jobs;

use App\Models\Ebay\EbayActionLog;
use App\Models\Ebay\EbayOffer;
use App\Models\Scrap\EbaySettings;
use App\Services\Ebay\EbayOAuthService;
use App\Services\Ebay\EbaySellClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Masowe ZAKOŃCZENIE własnych aukcji eBay (EndFixedPriceItem) — REALNE, NIEODWRACALNE.
 * Powrót do sprzedaży = wystawienie na nowo (nowy ItemID, historia i pozycja w wyszukiwarce przepadają).
 * Każda pozycja trafia do ebay_action_logs (zakładka „Logi"), także gdy eBay odmówi.
 */
class RunEbayEndListings implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public function __construct(
        public array $offerIds,
        public string $context = EbayActionLog::CONTEXT_MANUAL,
    ) {}

    public function handle(): void
    {
        $settings = EbaySettings::first();
        if (! $settings || ! $settings->isOauthConnected()) {
            return;
        }

        $client = new EbaySellClient($settings, new EbayOAuthService($settings));

        EbayOffer::whereIn('id', $this->offerIds)
            ->chunkById(50, function ($offers) use ($client) {
                foreach ($offers as $o) {
                    $before = (int) ($o->quantity ?? 0);

                    try {
                        $client->endListing($o->item_id, $o->marketplace);
                        $o->forceFill(['listing_status' => 'Ended', 'quantity' => 0])->save();
                        $this->log($o, EbayActionLog::STATUS_OK, $before);
                        usleep(300_000); // ~0.3 s throttle
                    } catch (\Throwable $e) {
                        Log::warning("eBay end {$o->item_id}/{$o->sku}: " . $e->getMessage());
                        $this->log($o, EbayActionLog::STATUS_ERROR, $before, $e->getMessage());
                    }
                }
            });
    }

    private function log(EbayOffer $o, string $status, int $qtyBefore, ?string $message = null): void
    {
        EbayActionLog::create([
            'action' => EbayActionLog::ACTION_END_LISTING,
            'context' => $this->context,
            'status' => $status,
            'marketplace' => $o->marketplace,
            'item_id' => $o->item_id,
            'sku' => $o->sku,
            'title' => $o->title,
            'listing_url' => $o->listing_url,
            'product_id' => $o->product_id,
            'qty_before' => $qtyBefore,
            'qty_after' => $status === EbayActionLog::STATUS_OK ? 0 : $qtyBefore,
            'message' => $message ? mb_substr($message, 0, 250) : 'Aukcja zakończona (EndFixedPriceItem).',
        ]);
    }
}
