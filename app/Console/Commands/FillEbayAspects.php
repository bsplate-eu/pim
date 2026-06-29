<?php

namespace App\Console\Commands;

use App\Models\Scrap\EbaySettings;
use App\Services\Ebay\EbayScrapService;
use Illuminate\Console\Command;

/**
 * Uzupełnia brakujące Herstellernummer/EAN ofert eBay — te, które przy pełnym
 * pomiarze dostały pusto przez rate-limit. Wolniejsze tempo + retry w kliencie.
 * Uruchom po scope:sync-ebay:  php artisan scope:fill-ebay-aspects
 */
class FillEbayAspects extends Command
{
    protected $signature = 'scope:fill-ebay-aspects';

    protected $description = 'Uzupełnia brakujące HN/EAN ofert eBay (rate-limited przy pełnym pomiarze)';

    public function handle(): int
    {
        $settings = EbaySettings::first();
        if (! $settings || ! $settings->hasCredentials()) {
            $this->error('Brak integracji eBay.');
            return self::FAILURE;
        }

        $this->info('Uzupełnianie brakujących HN/EAN…');
        $r = EbayScrapService::fromSettings($settings)->fillMissingAspects();
        $this->info("Sprawdzono {$r['checked']} ofert bez HN, uzupełniono {$r['filled']}.");

        return self::SUCCESS;
    }
}
