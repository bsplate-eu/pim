<?php

namespace App\Jobs;

use App\Models\Scrap\EbaySettings;
use App\Services\Ebay\EbayScrapService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Pełny pomiar eBay w tle (przycisk „Pełny pomiar" w Argo Scope → Rumuni → tab rynku).
 * $source = klucz rynku (ebay, ebay_fr…). null = wszystkie rynki po kolei. Wymaga workera kolejki.
 */
class RunEbayFullSync implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800; // 30 min — pełny pomiar ~1500 ofert z aspektami (1 rynek)

    public function __construct(public ?string $source = null) {}

    public function handle(): void
    {
        $settings = EbaySettings::first();
        if (! $settings || ! $settings->hasCredentials()) {
            return;
        }

        $sources = $this->source ? [$this->source] : EbayScrapService::marketKeys();
        foreach ($sources as $src) {
            if (EbayScrapService::isMarket($src)) {
                EbayScrapService::forMarket($settings, $src)->fullSync();
            }
        }
    }
}
