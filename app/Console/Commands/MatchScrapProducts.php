<?php

namespace App\Console\Commands;

use App\Services\Scrap\ProductMatcher;
use Illuminate\Console\Command;

/**
 * Auto-mapowanie ofert konkurenta ↔ nasze produkty (po kodzie / EAN).
 * php artisan scope:match-products ebay
 */
class MatchScrapProducts extends Command
{
    protected $signature = 'scope:match-products {source=ebay}';

    protected $description = 'Auto-mapuje oferty konkurenta do naszych produktów (herstellernummer↔product_code, ean↔ean)';

    public function handle(): int
    {
        $source = $this->argument('source');
        $this->info("Auto-mapowanie ({$source})…");
        $r = (new ProductMatcher())->matchSource($source);
        $this->info("Sprawdzono {$r['checked']} niezmapowanych, dopasowano {$r['matched']}.");

        return self::SUCCESS;
    }
}
