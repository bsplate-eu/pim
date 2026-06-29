<?php

namespace App\Console\Commands;

use App\Models\Connect\ChatbotReport;
use App\Models\Ksef\KsefSignalSettings;
use App\Services\Connect\SalesReportService;
use App\Services\Ksef\SignalSender;
use Illuminate\Console\Command;

/**
 * Dzienny raport sprzedaży (Argo Connect → Integracja chatboot) na WhatsApp.
 * Godzina z ustawień raportu; anty-duplikat przez last_sent_date.
 *
 * @see \App\Services\Connect\SalesReportService
 * @see \App\Services\Ksef\SignalSender
 */
class ConnectSalesReport extends Command
{
    protected $signature = 'connect:sales-report
        {--force : Wyślij nawet gdy wyłączone lub już wysłano dziś}
        {--dry-run : Pokaż treść bez wysyłki}';

    protected $description = 'Dzienny raport sprzedaży Argo Connect na WhatsApp.';

    public function handle(SalesReportService $sales, SignalSender $sender): int
    {
        $report = ChatbotReport::forKey(ChatbotReport::KEY_SALES);
        $force = (bool) $this->option('force');

        if (! $report->enabled && ! $force) {
            $this->info('Raport sprzedaży wyłączony — pomijam.');

            return self::SUCCESS;
        }

        if (! $force && optional($report->last_sent_date)->toDateString() === now()->toDateString()) {
            $this->info('Raport sprzedaży już wysłany dziś — pomijam.');

            return self::SUCCESS;
        }

        $message = $sales->renderTemplate($report->template ?: ChatbotReport::DEFAULT_SALES_TEMPLATE);

        if ($this->option('dry-run')) {
            $this->line('--- treść (dry-run) ---');
            $this->line($message);

            return self::SUCCESS;
        }

        // Numer/apikey raportu lub współdzielone z konfiguracją KSeF.
        $global = KsefSignalSettings::current();
        $phone = $report->phone ?: $global->phone;
        $apiKey = $report->api_key ?: $global->api_key;

        $result = $sender->sendTo($message, $phone, $apiKey);

        if (! $result['ok']) {
            $this->error('WhatsApp: ' . $result['error']);

            return self::FAILURE;
        }

        if ($report->exists) {
            $report->last_sent_date = now();
            $report->save();
        }

        $this->info('Wysłano raport sprzedaży.');

        return self::SUCCESS;
    }
}
