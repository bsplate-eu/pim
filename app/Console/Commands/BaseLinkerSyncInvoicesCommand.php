<?php

namespace App\Console\Commands;

use App\Models\Connect\BaseSettings;
use App\Services\BaseLinker\BaseLinkerInvoiceSyncService;
use Illuminate\Console\Command;

class BaseLinkerSyncInvoicesCommand extends Command
{
    protected $signature = 'baselinker:sync-invoices
        {--force : Ignoruj flagę enabled}
        {--base= : Synchronizuj tylko wybrany Base (ID lub label)}';

    protected $description = 'Synchronizuje faktury i korekty z wszystkich aktywnych Base\'ów BaseLinker';

    public function handle(): int
    {
        $query = BaseSettings::query();

        if ($filter = $this->option('base')) {
            $query->where(function ($q) use ($filter) {
                $q->where('id', (int) $filter)
                    ->orWhere('label', $filter);
            });
        }

        $bases = $query->orderBy('id')->get();

        if ($bases->isEmpty()) {
            $this->warn('Nie znaleziono żadnych Base\'ów.');
            return self::SUCCESS;
        }

        $failures = 0;

        foreach ($bases as $settings) {
            $label = $settings->label ?: ('Base #' . $settings->id);
            $this->line('');
            $this->info("=== {$label} (faktury) ===");

            if (! $settings->hasApiKey()) {
                $this->warn("[{$label}] Brak klucza API — pomijam.");
                continue;
            }

            if (! $settings->enabled && ! $this->option('force')) {
                $this->info("[{$label}] Wyłączone — pomijam.");
                continue;
            }

            try {
                $service = BaseLinkerInvoiceSyncService::fromSettings($settings);
                $dateFrom = $settings->sync_from_date ?: now()->subDays(30);
                $stats = $service->syncInvoices($dateFrom);

                $settings->forceFill(['last_invoice_sync_at' => now()])->save();

                $this->table(
                    ['Fetched', 'New', 'Updated'],
                    [[$stats['fetched'], $stats['new'], $stats['updated']]]
                );
            } catch (\Throwable $e) {
                $this->error("[{$label}] Błąd: " . $e->getMessage());
                $failures++;
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
