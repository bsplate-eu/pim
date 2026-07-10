<?php

namespace App\Console\Commands;

use App\Models\Ebay\EbayActionLog;
use App\Models\Scrap\EbaySettings;
use App\Services\Ebay\EbayOfferService;
use Illuminate\Console\Command;

/**
 * Automatyczne akcje eBay do crona (np. co godzinę). Każda reguła sama sprawdza swój przełącznik:
 *  - auto-przypisanie (auto_assign_enabled): mapowanie ofert → produkt po SKU (lokalne, bez OAuth),
 *  - auto-restock (auto_restock_enabled): stan aukcji 0 → wartość docelowa (wymaga połączonego konta).
 */
class EbayAutoActions extends Command
{
    protected $signature = 'ebay:auto-actions';

    protected $description = 'Automatyczne akcje eBay (auto-przypisanie po SKU + auto-restock stan 0 → docelowy)';

    public function handle(): int
    {
        $settings = EbaySettings::first();
        if (! $settings) {
            $this->error('Brak ustawień eBay.');

            return self::FAILURE;
        }

        $svc = EbayOfferService::fromSettings($settings);

        // Auto-przypisanie — lokalne mapowanie po SKU, nie wymaga połączonego konta.
        $assigned = $svc->applyAutoAssign(EbayActionLog::CONTEXT_CRON);
        $this->info("Auto-przypisanie: zmapowano {$assigned} ofert po SKU.");

        // Auto-restock — wymaga OAuth; metoda sama pomija gdy wyłączone/niepołączone.
        if ($settings->isOauthConnected()) {
            $restocked = $svc->applyAutoRestock(EbayActionLog::CONTEXT_CRON);
            $this->info("Auto-restock: podniesiono {$restocked} ofert (stan 0 → {$settings->auto_restock_to}).");
        } else {
            $this->warn('Auto-restock pominięty — konto eBay nie jest połączone (OAuth).');
        }

        return self::SUCCESS;
    }
}
