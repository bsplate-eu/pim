<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        if(config('database.connections.mysql.database') === 'pim_mvps_pro'){
            $schedule->command('baselinker-sheet:update')->hourly();
        }

        $schedule->command('db:backup')->dailyAt('00:30');
        $schedule->command('sources:sync')->dailyAt('01:00');
        $schedule->command('integrations:sync')->dailyAt('03:00');
        // Bez --queue worker bierze tylko kolejkę 'default'; connectory PrestaShop/LiteCart
        // dispatchują na nazwane kolejki sync-* (CatalogCreateJob itd.) i nigdy by nie ruszyły.
        $schedule->command('queue:work --queue=sync-catalog,sync-media,sync-blog,sync-analytics,default --stop-when-empty --timeout=7200 --memory=512')->everyMinute()->withoutOverlapping();

        // Argo Connect — synchronizacja zamówień BaseLinker (Multi-Base)
        $schedule->command('baselinker:sync-orders')
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->name('connect_baselinker_sync_orders');

        // Argo Connect — synchronizacja faktur i korekt BaseLinker
        $schedule->command('baselinker:sync-invoices')
            ->everyFifteenMinutes()
            ->withoutOverlapping()
            ->name('connect_baselinker_sync_invoices');

        // KSeF — przyrostowy delta-sync po API co 15 min (tylko nowe rejestracje od last_sync_at; lekkie)
        $schedule->command('ksef:import --since')
            ->everyFifteenMinutes()
            ->withoutOverlapping()
            ->name('ksef_import_delta');

        // KSeF — dzienna siatka bezpieczeństwa: pełny zakres po dacie wystawienia (łapie spóźnione rejestracje)
        $schedule->command('ksef:import')
            ->dailyAt('23:59')
            ->withoutOverlapping()
            ->name('ksef_import_daily');

        // KSeF — dzienne powiadomienie Signal o fakturach do zapłaty (godzina z ustawień, domyślnie 07:00)
        $signalTime = '07:00';
        try {
            $t = \App\Models\Ksef\KsefSignalSettings::query()->value('send_time');
            if ($t) {
                $signalTime = $t;
            }
        } catch (\Throwable) {
            // tabela może jeszcze nie istnieć (przed migracją) — zostaje domyślne 07:00
        }
        $schedule->command('ksef:signal-due')
            ->dailyAt($signalTime)
            ->withoutOverlapping()
            ->name('ksef_signal_due');

        // Argo Connect → Integracja chatboot — dzienny raport sprzedaży na WhatsApp (godzina z ustawień, domyślnie 20:00)
        $salesTime = '20:00';
        try {
            $t = \App\Models\Connect\ChatbotReport::query()->where('report_key', 'sales')->value('send_time');
            if ($t) {
                $salesTime = $t;
            }
        } catch (\Throwable) {
            // tabela może jeszcze nie istnieć (przed migracją) — zostaje domyślne 20:00
        }
        $schedule->command('connect:sales-report')
            ->dailyAt($salesTime)
            ->withoutOverlapping()
            ->name('connect_sales_report');

        // [argo-mail-pkg] Argo Mail — synchronizacja skrzynek IMAP (co minutę)
        $schedule->command('mail:sync')
            ->everyMinute()
            ->withoutOverlapping()
            ->name('argo_mail_sync');

        // [argo-mail-pkg] SMTP transakcyjny — czyszczenie starych logów wysyłki
        $schedule->command('mail:prune-logs')->dailyAt('02:30');

        // Argo Scope → Rumuni — pomiary konkurencji.
        // eBay: 6 rynków (każdy osobny katalog ~1,5 tys. ofert × getItem). DE codziennie (rynek główny);
        // pozostałe 5 rozłożone ~2 dziennie (rotacja dayOfYear % 3), by nie przekroczyć dziennego limitu Browse API.
        $schedule->command('scope:sync-ebay ebay')->dailyAt('03:00')->withoutOverlapping()->name('scope_sync_ebay_de');
        $schedule->command('scope:sync-ebay ebay_fr')->dailyAt('03:20')->when(fn () => now()->dayOfYear % 3 === 0)->withoutOverlapping()->name('scope_sync_ebay_fr');
        $schedule->command('scope:sync-ebay ebay_es')->dailyAt('03:50')->when(fn () => now()->dayOfYear % 3 === 0)->withoutOverlapping()->name('scope_sync_ebay_es');
        $schedule->command('scope:sync-ebay ebay_it')->dailyAt('03:20')->when(fn () => now()->dayOfYear % 3 === 1)->withoutOverlapping()->name('scope_sync_ebay_it');
        $schedule->command('scope:sync-ebay ebay_gb')->dailyAt('03:50')->when(fn () => now()->dayOfYear % 3 === 1)->withoutOverlapping()->name('scope_sync_ebay_gb');
        $schedule->command('scope:sync-ebay ebay_ch')->dailyAt('03:20')->when(fn () => now()->dayOfYear % 3 === 2)->withoutOverlapping()->name('scope_sync_ebay_ch');

        // Sklepy WWW: każdy 1× na 3 dni (rotacja dayOfYear % 3), rozłożone — max 2 dziennie o różnych
        // godzinach (04:00 i 05:30), nigdy wszystkie naraz. Każdy sklep wraca co 3 dni.
        $schedule->command('scope:sync-shop stahl')->dailyAt('04:00')->when(fn () => now()->dayOfYear % 3 === 0)->withoutOverlapping()->name('scope_sync_stahl');
        $schedule->command('scope:sync-shop rumunia')->dailyAt('04:00')->when(fn () => now()->dayOfYear % 3 === 1)->withoutOverlapping()->name('scope_sync_rumunia');
        $schedule->command('scope:sync-shop wegry')->dailyAt('04:00')->when(fn () => now()->dayOfYear % 3 === 2)->withoutOverlapping()->name('scope_sync_wegry');
        $schedule->command('scope:sync-shop francja')->dailyAt('05:30')->when(fn () => now()->dayOfYear % 3 === 0)->withoutOverlapping()->name('scope_sync_francja');
        $schedule->command('scope:sync-shop czechy')->dailyAt('05:30')->when(fn () => now()->dayOfYear % 3 === 1)->withoutOverlapping()->name('scope_sync_czechy');
        $schedule->command('scope:sync-shop hiszpania')->dailyAt('05:30')->when(fn () => now()->dayOfYear % 3 === 2)->withoutOverlapping()->name('scope_sync_hiszpania');
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
