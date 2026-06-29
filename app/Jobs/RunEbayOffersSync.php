<?php

namespace App\Jobs;

use App\Models\Scrap\EbaySettings;
use App\Services\Ebay\EbayOfferService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Pobranie własnych ofert eBay w tle (Sell/Trading API). Wymaga połączonego konta (OAuth user-token).
 */
class RunEbayOffersSync implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public function __construct(public ?string $marketplace = null) {}

    public function handle(): void
    {
        $settings = EbaySettings::first();
        if ($settings && $settings->isOauthConnected()) {
            EbayOfferService::fromSettings($settings)->syncActiveListings($this->marketplace);
        }
    }
}
