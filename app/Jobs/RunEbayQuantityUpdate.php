<?php

namespace App\Jobs;

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
 * Masowa zmiana ILOŚCI własnych ofert eBay: increase (+), decrease (−), set (=).
 * ReviseInventoryStatus per oferta z throttlingiem. REALNE zmiany na żywym koncie.
 */
class RunEbayQuantityUpdate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public function __construct(
        public array $offerIds,
        public string $mode,   // increase | decrease | set
        public int $amount,
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
                    $current = (int) ($o->quantity ?? 0);
                    $new = match ($this->mode) {
                        'increase' => $current + $this->amount,
                        'decrease' => max(0, $current - $this->amount),
                        'set' => $this->amount,
                        default => $current,
                    };
                    if ($new === $current) {
                        continue;
                    }

                    try {
                        $client->reviseQuantity($o->item_id, (string) $o->sku, $new, $o->marketplace, (int) $o->quantity_sold);
                        $o->forceFill(['quantity' => $new])->save();
                        usleep(300_000); // ~0.3 s throttle
                    } catch (\Throwable $e) {
                        Log::warning("eBay qty {$o->item_id}/{$o->sku}: " . $e->getMessage());
                    }
                }
            });
    }
}
