<?php

namespace App\Jobs;

use App\Models\Ebay\EbayOffer;
use App\Models\PricelistProduct;
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
 * Masowa zmiana cen WŁASNYCH ofert eBay na cenę z cennika (netto × (1+VAT) = brutto eBay).
 * ReviseInventoryStatus per oferta z throttlingiem (rate-limit eBay). Tylko zmapowane oferty.
 * REALNE zmiany na żywym koncie — uruchamiać po teście na Sandboxie.
 */
class RunEbayPriceUpdate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public function __construct(
        public array $offerIds,
        public int $pricelistId,
        public float $vat = 0,
    ) {}

    public function handle(): void
    {
        $settings = EbaySettings::first();
        if (! $settings || ! $settings->isOauthConnected()) {
            return;
        }

        $prices = PricelistProduct::exportPriceMap($this->pricelistId);
        $client = new EbaySellClient($settings, new EbayOAuthService($settings));

        EbayOffer::whereIn('id', $this->offerIds)
            ->whereNotNull('product_id')
            ->chunkById(50, function ($offers) use ($client, $prices) {
                foreach ($offers as $o) {
                    $net = (float) ($prices[$o->product_id] ?? 0);
                    if ($net <= 0) {
                        continue;
                    }
                    $gross = round($net * (1 + $this->vat / 100), 2);

                    try {
                        $client->revisePrice($o->item_id, (string) $o->sku, $gross, $o->marketplace);
                        $o->forceFill(['price' => $gross])->save();   // odśwież lokalnie
                        usleep(300_000);                              // ~0.3 s throttle (rate-limit)
                    } catch (\Throwable $e) {
                        Log::warning("eBay revise {$o->item_id}/{$o->sku}: " . $e->getMessage());
                    }
                }
            });
    }
}
