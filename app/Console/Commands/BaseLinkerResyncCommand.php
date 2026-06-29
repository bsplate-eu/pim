<?php

namespace App\Console\Commands;

use App\Models\Connect\BaseSettings;
use App\Services\BaseLinker\BaseLinkerOrderSyncService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class BaseLinkerResyncCommand extends Command
{
    protected $signature = 'baselinker:resync
        {base : ID lub label Base (np. 2 albo "Odysseya")}
        {--since= : Data od kiedy ściągać (YYYY-MM-DD). Jeśli puste — używa sync_from_date z bazy}
        {--keep-orders : Nie usuwaj istniejących zamówień przed resyncem (domyślnie zostają)}';

    protected $description = 'Pełna resynchronizacja zamówień: zeruje kursor (last_sync_order_id) i ciągnie wszystko od podanej daty (filtr date_from, niepotwierdzone + archiwum).';

    public function handle(): int
    {
        $filter = $this->argument('base');

        $settings = BaseSettings::query()
            ->where(function ($q) use ($filter) {
                $q->where('id', (int) $filter)
                    ->orWhere('label', $filter);
            })
            ->first();

        if (! $settings) {
            $this->error("Nie znaleziono Base: {$filter}");
            return self::FAILURE;
        }

        if (! $settings->hasApiKey()) {
            $this->error("[{$settings->label}] Brak klucza API.");
            return self::FAILURE;
        }

        // Ustal `sync_from_date` z opcji lub z bazy
        if ($since = $this->option('since')) {
            try {
                $settings->sync_from_date = Carbon::parse($since)->startOfDay();
            } catch (\Throwable $e) {
                $this->error("Niepoprawna data: {$since}. Użyj YYYY-MM-DD.");
                return self::FAILURE;
            }
        }

        if (! $settings->sync_from_date) {
            $this->error('Brak sync_from_date — podaj --since=YYYY-MM-DD.');
            return self::FAILURE;
        }

        // Zeruj kursor — wymusza ścieżkę `date_from` w buildInitialCursor
        $settings->last_sync_order_id = 0;
        $settings->save();

        $this->info("=== Pełny resync: {$settings->label} ===");
        $this->line("sync_from_date: {$settings->sync_from_date->toDateString()}");
        $this->line("last_sync_order_id: 0 (zresetowane)");
        $this->line('');

        $log = BaseLinkerOrderSyncService::fromSettings($settings)->syncOrders('manual-resync');

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

        return $log->status === 'success' ? self::SUCCESS : self::FAILURE;
    }
}
