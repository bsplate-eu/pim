<?php

namespace App\Console\Commands;

use App\Models\Mail\Account;
use App\Services\Mail\MailSyncService;
use Illuminate\Console\Command;

class MailImport extends Command
{
    protected $signature = 'mail:import
        {--account= : ID konkretnej skrzynki (puste = wszystkie aktywne)}
        {--folder= : Ścieżka konkretnego folderu IMAP (np. "GlobKurier"; puste = wszystkie zwykłe foldery)}
        {--months= : Okno wstecz w miesiącach — NADPISUJE ustawienie skrzynki (puste = wg ustawień skrzynki)}
        {--all : Cała historia skrzynki (ignoruje okno z ustawień)}
        {--batch=100 : Wielkość paczki (maili na raz)}';

    protected $description = 'Argo Mail — import wsadowy (paczkami, bez limitu czasu; domyślnie wg okna z ustawień skrzynki)';

    public function handle(MailSyncService $service): int
    {
        $batch = max(10, (int) $this->option('batch'));
        $all = (bool) $this->option('all');
        $monthsOpt = $this->option('months');
        $monthsOverride = ($monthsOpt !== null && $monthsOpt !== '') ? max(0, (int) $monthsOpt) : null;
        $folderOpt = $this->option('folder');
        $folderPath = ($folderOpt !== null && $folderOpt !== '') ? (string) $folderOpt : null;

        $query = Account::query()->where('is_active', true);
        if ($id = $this->option('account')) {
            $query->where('id', $id);
        }
        $accounts = $query->get();

        if ($accounts->isEmpty()) {
            $this->error('Brak aktywnych skrzynek do importu.');

            return self::FAILURE;
        }

        foreach ($accounts as $account) {
            // Okno: --all → cała historia; --months=N → N; inaczej → ustawienie skrzynki (sync_window_months).
            if ($all) {
                $months = 0;
            } elseif ($monthsOverride !== null) {
                $months = $monthsOverride;
            } else {
                $months = max(1, (int) $account->sync_window_months);
            }

            $fromSettings = (! $all && $monthsOverride === null);
            $scope = $months > 0
                ? "ostatnie {$months} mies.".($fromSettings ? ' (wg ustawień skrzynki)' : '')
                : 'cała historia';

            $folderInfo = $folderPath !== null ? ", folder: {$folderPath}" : ', wszystkie zwykłe foldery';
            $this->line("📥 <info>{$account->email}</info> — import ({$scope}{$folderInfo}, paczki po {$batch})…");

            $result = $service->import(
                $account,
                $months,
                $batch,
                $folderPath,
                function (int $total, int $new, int $updated, int $page) {
                    $this->line("   …paczka {$page}: razem {$total} (nowe {$new}, zaktualizowane {$updated})");
                }
            );

            if ($result['ok']) {
                $this->info("   ✓ {$account->email}: pobrane {$result['fetched']}, nowych {$result['new']}, zaktualizowanych {$result['updated']}.");
            } else {
                $this->error("   ✗ {$account->email}: ".($result['message'] ?? 'nieznany błąd'));
            }
        }

        return self::SUCCESS;
    }
}
