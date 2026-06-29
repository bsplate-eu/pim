<?php

namespace App\Console\Commands;

use App\Models\Scrap\EbaySettings;
use App\Services\Ebay\EbayOfferService;
use Illuminate\Console\Command;

/**
 * Pobierz własne oferty eBay (Sell/Trading) → ebay_offers. Wymaga połączonego konta (OAuth).
 * Użycie: php artisan ebay:sync-offers [--marketplace=EBAY_DE]
 */
class SyncEbayOffers extends Command
{
    protected $signature = 'ebay:sync-offers {--marketplace= : Rynek (EBAY_DE/EBAY_PL/…); domyślnie z ustawień}';

    protected $description = 'Pobierz własne aktywne oferty eBay do tabeli ebay_offers (+ auto-match po SKU)';

    public function handle(): int
    {
        $settings = EbaySettings::first();
        if (! $settings || ! $settings->isOauthConnected()) {
            $this->error('Konto eBay nie jest połączone (OAuth). Połącz w Connect → Integracje → Ebay.');

            return self::FAILURE;
        }

        try {
            $r = EbayOfferService::fromSettings($settings)->syncActiveListings($this->option('marketplace'));
        } catch (\Throwable $e) {
            $this->error('Błąd pobierania: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info("Rynek {$r['marketplace']}: pobrano {$r['fetched']} ofert (nowych {$r['new']}, stron {$r['pages']}, zmapowano po SKU {$r['matched']}).");

        return self::SUCCESS;
    }
}
