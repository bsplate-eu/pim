<?php

namespace App\Console\Commands;

use App\Models\Scrap\EbaySettings;
use App\Services\Ebay\EbayScrapService;
use Illuminate\Console\Command;

/**
 * Uzupełnia brakujące Herstellernummer/EAN ofert eBay — te, które przy pełnym
 * pomiarze dostały pusto przez rate-limit ALBO mają atrybut nazwany po lokalnemu
 * (eBay.fr „Numéro de pièce fabricant", eBay.es tylko w opisie „Nº de artículo").
 * Uruchom po scope:sync-ebay:  php artisan scope:fill-ebay-aspects ebay_fr
 */
class FillEbayAspects extends Command
{
    protected $signature = 'scope:fill-ebay-aspects {source=ebay : rynek eBay (ebay|ebay_fr|ebay_es|ebay_it|ebay_gb|ebay_ch)}';

    protected $description = 'Uzupełnia brakujące HN/EAN ofert eBay danego rynku (rate-limited przy pełnym pomiarze)';

    public function handle(): int
    {
        $settings = EbaySettings::first();
        if (! $settings || ! $settings->hasCredentials()) {
            $this->error('Brak integracji eBay.');
            return self::FAILURE;
        }

        $source = (string) $this->argument('source');
        if (! EbayScrapService::isMarket($source)) {
            $this->error("Nieznany rynek: {$source}. Dostępne: " . implode(', ', EbayScrapService::marketKeys()));
            return self::FAILURE;
        }

        $this->info("Uzupełnianie brakujących HN/EAN ({$source})…");
        $r = EbayScrapService::forMarket($settings, $source)->fillMissingAspects();
        $this->info("Sprawdzono {$r['checked']} ofert bez HN, uzupełniono {$r['filled']}.");

        return self::SUCCESS;
    }
}
