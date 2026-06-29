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
 * Pełny pomiar eBay w tle (przycisk „Pełny pomiar" w Argo Scope → Rumuni → Ebay).
 * Wymaga działającego workera kolejki.
 */
class RunEbayFullSync implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800; // 30 min — pełny pomiar ~1500 ofert z aspektami

    public function handle(): void
    {
        $settings = EbaySettings::first();
        if ($settings && $settings->hasCredentials()) {
            EbayScrapService::fromSettings($settings)->fullSync();
        }
    }
}
