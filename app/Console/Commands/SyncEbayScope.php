<?php

namespace App\Console\Commands;

use App\Models\Scrap\EbaySettings;
use App\Services\Ebay\EbayScrapService;
use Illuminate\Console\Command;

/**
 * Pełny pomiar eBay (Scut Protection): oferty + Herstellernummer/EAN + diff + cennik.
 * Uruchamiać ręcznie:  php artisan scope:sync-ebay
 * Albo z crona (Laravel scheduler, np. 1×/dobę).
 */
class SyncEbayScope extends Command
{
    protected $signature = 'scope:sync-ebay';

    protected $description = 'Pełny pomiar eBay (Rumuni): oferty + HN/EAN + diff (nowe/wycofane/ceny) + cennik';

    public function handle(): int
    {
        $settings = EbaySettings::first();
        if (! $settings || ! $settings->hasCredentials()) {
            $this->error('Brak integracji eBay — skonfiguruj App ID / Cert ID w Connect → Integracje → Ebay.');
            return self::FAILURE;
        }

        $this->info('Pełny pomiar eBay (' . $settings->seller . ')…');
        $stats = EbayScrapService::fromSettings($settings)->fullSync();
        $this->info(sprintf(
            'Gotowe: pobrano %d | nowe +%d | wycofane -%d | ceny ↑%d ↓%d.',
            $stats['fetched'], $stats['new'], $stats['removed'], $stats['price_up'], $stats['price_down']
        ));

        return self::SUCCESS;
    }
}
