<?php

namespace App\Console\Commands;

use App\Models\Connect\BaseSettings;
use App\Services\BaseLinker\BaseLinkerOrderSyncService;
use Illuminate\Console\Command;

class BaseLinkerSyncOrdersCommand extends Command
{
    protected $signature = 'baselinker:sync-orders
        {--force : Ignoruj flagę enabled i interwał}
        {--base= : Synchronizuj tylko wybrany Base (ID lub label)}';

    protected $description = 'Synchronizuje zamówienia z wszystkich aktywnych Base\'ów BaseLinker do PIM Connect';

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
            $this->info("=== {$label} ===");

            if (! $settings->hasApiKey()) {
                $this->warn("[{$label}] Brak klucza API — pomijam.");
                continue;
            }

            if (! $settings->enabled && ! $this->option('force')) {
                $this->info("[{$label}] Wyłączone — pomijam.");
                continue;
            }

            if (! $this->option('force')
                && $settings->last_sync_at
                && $settings->last_sync_at->addMinutes((int) $settings->sync_interval_minutes)->isFuture()
            ) {
                $this->info(sprintf(
                    '[%s] Ostatni sync: %s, kolejny za %d min.',
                    $label,
                    $settings->last_sync_at->toDateTimeString(),
                    (int) now()->diffInMinutes($settings->last_sync_at->addMinutes((int) $settings->sync_interval_minutes), false)
                ));
                continue;
            }

            $this->info("[{$label}] Startuję synchronizację…");
            $log = BaseLinkerOrderSyncService::fromSettings($settings)->syncOrders(
                $this->option('force') ? 'manual' : 'scheduled'
            );

            $this->table(
                ['Status', 'Fetched', 'New', 'Updated', 'Duration (s)', 'Error'],
                [[
                    $log->status,
                    $log->orders_fetched,
                    $log->orders_new,
                    $log->orders_updated,
                    $log->duration_seconds ?? '-',
                    $log->error_message ?? '-',
                ]]
            );

            if ($log->status !== 'success') {
                $failures++;
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
