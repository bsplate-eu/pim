<?php

namespace App\Console\Commands;

use App\Models\Ksef\KsefInvoice;
use App\Services\Ksef\NbpRateService;
use Illuminate\Console\Command;

/**
 * Dolicza kwotę w PLN (`amount_pln`) tam, gdzie jej brakuje — kurs średni NBP z dnia roboczego
 * przed datą wystawienia. Sumy na liście kosztów liczą się WYŁĄCZNIE z `amount_pln`, więc każda
 * pozycja bez kursu jest z nich wypadnięta (widoczna dziura zamiast złej kwoty).
 *
 *   php artisan ksef:fx-fill              # tylko braki
 *   php artisan ksef:fx-fill --all        # przelicz wszystko od nowa (np. po korekcie kursu)
 */
class KsefFxFill extends Command
{
    protected $signature = 'ksef:fx-fill
        {company? : pareto|bsp (puste = wszystkie)}
        {--all : przelicz też pozycje, które mają już kwotę w PLN}';

    protected $description = 'Przelicza kwoty faktur/kosztów na PLN kursem NBP i zapisuje kurs przy rekordzie.';

    public function handle(NbpRateService $nbp): int
    {
        $query = KsefInvoice::query();
        if ($company = $this->argument('company')) {
            $query->where('company', $company);
        }
        if (! $this->option('all')) {
            $query->whereNull('amount_pln');
        }

        $done = 0;
        $failed = 0;
        $total = 0;

        foreach ($query->orderBy('id')->cursor() as $row) {
            $total++;
            $currency = strtoupper((string) ($row->currency ?: 'PLN'));

            if ($currency === 'PLN') {
                $row->amount_pln = round((float) $row->amount, 2);
                $row->fx_rate = 1;
                $row->fx_date = null;
                $row->save();
                $done++;
                continue;
            }

            $fx = $nbp->toPln((float) $row->amount, $currency, $row->issue_date ?: now());
            if ($fx === null) {
                $this->warn("FV {$row->number} ({$currency}): NBP nie oddał kursu — pomijam.");
                $failed++;
                continue;
            }

            $row->amount_pln = $fx['amount_pln'];
            $row->fx_rate = $fx['fx_rate'];
            $row->fx_date = $fx['fx_date'];
            $row->save();
            $done++;

            $this->line(sprintf(
                '  %-28s %10s %s  ->  %12s PLN  (NBP %s, kurs %s)',
                mb_substr((string) $row->number, 0, 26),
                number_format((float) $row->amount, 2, ',', ' '),
                $currency,
                number_format($fx['amount_pln'], 2, ',', ' '),
                $fx['fx_date'],
                $fx['fx_rate'],
            ));
        }

        $this->info("Sprawdzono {$total}: przeliczono {$done}, bez kursu {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
