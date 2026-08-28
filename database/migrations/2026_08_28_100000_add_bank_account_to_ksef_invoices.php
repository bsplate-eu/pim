<?php

use App\Services\Ksef\KsefInvoiceParser;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Numer rachunku z faktury kosztowej (KSeF: Fa/Platnosc/RachunekBankowy/NrRB) w osobnej kolumnie —
 * żeby dało się go skopiować do przelewu bez otwierania PDF-a.
 *
 * Dane bierzemy z zapisanego XML-a faktury, więc backfill NIE odpytuje KSeF (zero ryzyka rate-limitu).
 * Sprzedawca bywa podaje kilka rachunków (PLN + walutowy) — pierwszy ląduje w kolumnie,
 * pełna lista (z nazwą banku/SWIFT) w `bank_accounts` pod dymek.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ksef_invoices', function (Blueprint $table) {
            $table->string('bank_account', 64)->nullable()->after('contractor');
            $table->json('bank_accounts')->nullable()->after('bank_account');
        });

        DB::table('ksef_invoices')
            ->whereNotNull('xml')
            ->orderBy('id')
            ->select('id', 'xml')
            ->chunk(200, function ($rows) {
                foreach ($rows as $row) {
                    try {
                        $accounts = KsefInvoiceParser::parse((string) $row->xml)['bank_accounts'] ?? [];
                    } catch (\Throwable $e) {
                        continue; // uszkodzony XML nie może wywrócić migracji
                    }
                    if (! $accounts) {
                        continue;
                    }
                    DB::table('ksef_invoices')->where('id', $row->id)->update([
                        'bank_account' => $accounts[0]['nr'],
                        'bank_accounts' => json_encode($accounts, JSON_UNESCAPED_UNICODE),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('ksef_invoices', function (Blueprint $table) {
            $table->dropColumn(['bank_account', 'bank_accounts']);
        });
    }
};
