<?php

namespace App\Console\Commands;

use App\Models\Scrap\EbaySettings;
use App\Services\Ebay\EbayScrapService;
use Illuminate\Console\Command;

/**
 * Pełny pomiar eBay (Scut Protection) — PER RYNEK: oferty + Herstellernummer/EAN + diff.
 * Uruchamianie:
 *   php artisan scope:sync-ebay             → wszystkie rynki po kolei
 *   php artisan scope:sync-ebay ebay_fr     → tylko jeden rynek
 * Cron uruchamia rynki rozłożone w czasie (Kernel) — patrz harmonogram.
 */
class SyncEbayScope extends Command
{
    protected $signature = 'scope:sync-ebay {source? : klucz rynku (ebay, ebay_fr, ebay_it, ebay_es, ebay_gb, ebay_ch); brak = wszystkie}';

    protected $description = 'Pełny pomiar eBay (Rumuni) per rynek: oferty + HN/EAN + diff (nowe/wycofane/ceny)';

    public function handle(): int
    {
        $settings = EbaySettings::first();
        if (! $settings || ! $settings->hasCredentials()) {
            $this->error('Brak integracji eBay — skonfiguruj App ID / Cert ID w Connect → Integracje → Ebay.');

            return self::FAILURE;
        }

        $arg = $this->argument('source');
        $sources = $arg ? [$arg] : EbayScrapService::marketKeys();

        foreach ($sources as $src) {
            if (! EbayScrapService::isMarket($src)) {
                $this->warn("Pomijam nieznany rynek: {$src}");
                continue;
            }

            $label = EbayScrapService::MARKETS[$src]['label'];
            $this->info("Pełny pomiar {$label} ({$settings->seller})…");

            $stats = EbayScrapService::forMarket($settings, $src)->fullSync();

            $this->info(sprintf(
                '  %s: pobrano %d | nowe +%d | wycofane -%d | ceny ↑%d ↓%d.',
                $label, $stats['fetched'], $stats['new'], $stats['removed'], $stats['price_up'], $stats['price_down']
            ));
        }

        return self::SUCCESS;
    }
}
