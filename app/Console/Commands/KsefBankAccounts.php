<?php

namespace App\Console\Commands;

use App\Models\Ksef\KsefInvoice;
use App\Services\Ksef\KsefInvoiceParser;
use Illuminate\Console\Command;

/**
 * Uzupełnia kolumnę „Nr konta" na fakturach już zaciągniętych z KSeF.
 * Czyta ZAPISANY XML z bazy — nie odpytuje KSeF, więc można puszczać bez limitów.
 *
 *   php artisan ksef:bank-accounts               # tylko FV bez numeru
 *   php artisan ksef:bank-accounts pareto --all  # przeparsuj wszystkie FV Pareto od nowa
 */
class KsefBankAccounts extends Command
{
    protected $signature = 'ksef:bank-accounts
        {company? : pareto|bsp (puste = wszystkie)}
        {--all : przelicz też faktury, które mają już numer konta}';

    protected $description = 'Wyciąga numer rachunku (NrRB) z zapisanego XML faktur KSeF do osobnej kolumny.';

    public function handle(): int
    {
        $query = KsefInvoice::query()->whereNotNull('xml');
        if ($company = $this->argument('company')) {
            $query->where('company', $company);
        }
        if (! $this->option('all')) {
            $query->whereNull('bank_account');
        }

        $filled = 0;
        $empty = 0;
        $total = 0;

        foreach ($query->orderBy('id')->cursor() as $invoice) {
            $total++;
            try {
                $accounts = KsefInvoiceParser::parse((string) $invoice->xml)['bank_accounts'] ?? [];
            } catch (\Throwable $e) {
                $this->warn("FV {$invoice->number}: nie udało się sparsować XML.");
                continue;
            }

            if (! $accounts) {
                $empty++;
                continue;
            }

            $invoice->bank_account = $accounts[0]['nr'];
            $invoice->bank_accounts = $accounts;
            $invoice->save();
            $filled++;
        }

        $this->info("Sprawdzono {$total} FV: uzupełniono {$filled}, bez rachunku w XML {$empty}.");

        return self::SUCCESS;
    }
}
