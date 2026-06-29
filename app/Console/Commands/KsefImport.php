<?php

namespace App\Console\Commands;

use App\Models\Ksef\KsefInvoice;
use App\Models\Ksef\KsefSettings;
use App\Services\Ksef\KsefClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Zaciąganie faktur z KSeF (eksport zbiorczy) z CLI — bez limitu czasu web-requestu.
 * Codzienny cron pobiera „nowości" (domyślnie ostatnie 45 dni); idempotentne (firstOrNew po ksef_ref),
 * NIE nadpisuje statusu „opłacone" ani kategorii.
 *
 *   php artisan ksef:import                      # obie firmy, ostatnie 45 dni, zakupowe
 *   php artisan ksef:import pareto --from=2026-01-01   # backfill całego roku dla Pareto
 *   php artisan ksef:import bsp --type=Subject1         # sprzedażowe BSP
 */
class KsefImport extends Command
{
    protected $signature = 'ksef:import
        {company? : pareto|bsp (puste = wszystkie firmy z tokenem)}
        {--from= : data od (Y-m-d); domyślnie wg --days}
        {--to= : data do (Y-m-d); domyślnie dziś}
        {--days=45 : ostatnie N dni, gdy brak --from}
        {--since : tryb przyrostowy — tylko faktury zarejestrowane w KSeF od ostatniego sync (last_sync_at)}
        {--type=Subject2 : Subject2=zakupowe (domyślne), Subject1=sprzedażowe}';

    protected $description = 'Zaciąga faktury z KSeF (eksport zbiorczy) i zapisuje do bazy.';

    public function handle(): int
    {
        @set_time_limit(0);

        $type = $this->option('type') === 'Subject1' ? 'Subject1' : 'Subject2';
        $since = (bool) $this->option('since');
        $companies = $this->argument('company') ? [$this->argument('company')] : ['pareto', 'bsp'];

        $total = 0;
        foreach ($companies as $company) {
            $settings = KsefSettings::where('company', $company)->first();
            if (! $settings || ! $settings->hasToken() || empty($settings->nip)) {
                $this->warn("[{$company}] brak poświadczeń KSeF — pomijam.");
                continue;
            }

            $stamp = Carbon::now(); // znacznik do zapisania po sukcesie (tryb --since)

            if ($since) {
                $watermark = $settings->last_sync_at; // Carbon|null
                $from = $watermark ? $watermark->copy()->subHour() : Carbon::now()->subDays(2);
                $to = Carbon::now();
                $dateType = 'Invoicing'; // data rejestracji w KSeF (delta przyrostowa)
            } else {
                $to = $this->option('to') ? Carbon::parse($this->option('to')) : Carbon::now();
                $from = $this->option('from')
                    ? Carbon::parse($this->option('from'))
                    : (clone $to)->subDays(max(1, (int) $this->option('days')));
                $dateType = 'Issue'; // data wystawienia (backfill)
            }

            $fromDt = (new \DateTimeImmutable($from->toIso8601String()))->setTimezone(new \DateTimeZone('UTC'));
            $toDt = (new \DateTimeImmutable($to->toIso8601String()))->setTimezone(new \DateTimeZone('UTC'));

            $t0 = microtime(true);
            try {
                $invoices = (new KsefClient($settings))->exportInvoices($fromDt, $toDt, $type, $dateType);
            } catch (\Throwable $e) {
                $this->error("[{$company}] błąd KSeF: " . $e->getMessage());
                report($e);
                continue;
            }

            $count = 0;
            foreach ($invoices as $inv) {
                $ref = $inv['ksef_ref'] ?? null;
                if (! $ref) {
                    continue;
                }

                $contractor = $type === 'Subject2'
                    ? ($inv['seller']['name'] ?? $inv['seller']['nip'] ?? null)
                    : ($inv['buyer']['name'] ?? $inv['buyer']['nip'] ?? null);

                $row = KsefInvoice::firstOrNew(['company' => $company, 'ksef_ref' => $ref]);
                $row->issue_date = $inv['issue_date'] ?? null;
                $row->number = $inv['number'] ?? $ref;
                $row->contractor = $contractor;
                $row->items_text = $inv['items_text'] ?? null;
                $row->xml = $inv['xml'] ?? null;
                if (! empty($inv['due_date'])) {
                    $row->due_date = $inv['due_date'];
                }
                $row->amount = is_numeric($inv['gross'] ?? null) ? (float) $inv['gross'] : (float) ($row->amount ?? 0);
                $row->currency = $inv['currency'] ?? ($row->currency ?? 'PLN');
                $row->source = 'ksef';
                $row->imported_at = now();
                if (! $row->exists) {
                    $row->status = 'unpaid'; // status płatności prowadzimy u siebie
                }
                $row->save();
                $count++;
            }

            if ($since) {
                $settings->last_sync_at = $stamp; // przesuwamy znacznik dopiero po udanym pobraniu
                $settings->save();
            }

            $total += $count;
            $this->info(sprintf(
                '[%s] %s%s: pobrano %d, zapisano %d w %.1fs (%s..%s)',
                $company, $type, $since ? ' delta' : '', count($invoices), $count, microtime(true) - $t0,
                $from->format('Y-m-d H:i'), $to->format('Y-m-d H:i')
            ));
        }

        $this->info("Razem zapisano/zaktualizowano: {$total}.");

        return self::SUCCESS;
    }
}
