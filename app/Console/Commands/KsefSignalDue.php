<?php

namespace App\Console\Commands;

use App\Models\Ksef\KsefSignalSettings;
use App\Services\Ksef\DuePaymentsService;
use App\Services\Ksef\SignalSender;
use Illuminate\Console\Command;

/**
 * Dzienne powiadomienie Signal o fakturach KSeF do zapłaty „na dziś".
 * Uruchamiane z harmonogramu o godzinie z ustawień (domyślnie 07:00).
 * Anty-duplikat: nie wysyła drugi raz tego samego dnia (last_sent_date).
 *
 * @see \App\Services\Ksef\SignalSender
 * @see \App\Services\Ksef\DuePaymentsService
 */
class KsefSignalDue extends Command
{
    protected $signature = 'ksef:signal-due
        {--force : Wyślij nawet gdy wyłączone lub już wysłano dziś}
        {--dry-run : Pokaż treść bez wysyłki}';

    protected $description = 'Powiadomienie Signal o fakturach KSeF do zapłaty dziś.';

    public function handle(DuePaymentsService $due, SignalSender $sender): int
    {
        $settings = KsefSignalSettings::current();
        $force = (bool) $this->option('force');

        if (! $settings->exists && ! $force) {
            $this->warn('Brak konfiguracji Signal — pomijam.');

            return self::SUCCESS;
        }

        if (! $settings->enabled && ! $force) {
            $this->info('Powiadomienia Signal wyłączone — pomijam.');

            return self::SUCCESS;
        }

        if (! $force && optional($settings->last_sent_date)->toDateString() === now()->toDateString()) {
            $this->info('Powiadomienie już wysłane dziś — pomijam.');

            return self::SUCCESS;
        }

        $message = $due->renderTemplate($settings->template ?: KsefSignalSettings::DEFAULT_TEMPLATE);

        if ($this->option('dry-run')) {
            $this->line('--- treść (dry-run) ---');
            $this->line($message);

            return self::SUCCESS;
        }

        $result = $sender->send($message, $settings);

        if (! $result['ok']) {
            $this->error('Signal: ' . $result['error']);

            return self::FAILURE;
        }

        // Anty-duplikat — zapisujemy dopiero po udanej wysyłce (tylko realny rekord).
        if ($settings->exists) {
            $settings->last_sent_date = now();
            $settings->save();
        }

        $this->info('Wysłano powiadomienie Signal.');

        return self::SUCCESS;
    }
}
