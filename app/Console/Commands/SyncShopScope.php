<?php

namespace App\Console\Commands;

use App\Services\Scrap\ShopScrapService;
use Illuminate\Console\Command;

/**
 * Pomiar sklepu konkurenta (Rumuni → Niemcy / Węgry / Rumunia): katalog + kod/EAN + diff.
 * Ręcznie:  php artisan scope:sync-shop stahl   (test: --limit=20)
 *           php artisan scope:sync-shop wegry
 *           php artisan scope:sync-shop rumunia
 * Z crona:  zaplanowane w App\Console\Kernel — „stały scrap".
 */
class SyncShopScope extends Command
{
    protected $signature = 'scope:sync-shop {source : stahl|wegry|rumunia} {--limit= : Ogranicz liczbę produktów (test)} {--delay=200 : Opóźnienie między żądaniami [ms]}';

    protected $description = 'Pomiar sklepu konkurenta (Niemcy/Węgry/Rumunia): katalog + kod/EAN + diff (nowe/wycofane/ceny)';

    public function handle(): int
    {
        $source = (string) $this->argument('source');
        if (! ShopScrapService::isShop($source)) {
            $this->error("Nieznany sklep: {$source}. Dostępne: " . implode(', ', array_keys(ShopScrapService::SHOPS)));

            return self::FAILURE;
        }

        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $delay = (int) $this->option('delay');

        $this->info("Pomiar sklepu: {$source}…");

        $bar = null;
        $stats = ShopScrapService::make($source)->fullSync($limit, $delay, function (int $done, int $total) use (&$bar) {
            if ($bar === null) {
                $bar = $this->output->createProgressBar($total);
                $bar->start();
            }
            $bar->setMaxSteps($total);
            $bar->setProgress($done);
        });
        if ($bar) {
            $bar->finish();
            $this->newLine();
        }

        $this->info(sprintf(
            'Gotowe: produktów %d | nowe +%d | wycofane -%d | ceny ↑%d ↓%d | błędy %d.',
            $stats['fetched'], $stats['new'], $stats['removed'], $stats['price_up'], $stats['price_down'], $stats['errors'],
        ));

        return self::SUCCESS;
    }
}
