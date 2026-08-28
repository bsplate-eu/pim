<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ręczne koszty w liście KSeF: FV kosztowa wpisana z ręki, ZUS, VAT, CIT, OSS.
 * Siedzą w tej samej tabeli co FV z KSeF (`source = 'manual'`), żeby wchodziły w te same
 * filtry, sumy, checkbox „Opłacone", kafelek „Do zapłaty" i powiadomienie o 7:00.
 *
 * `amount_pln` — kwota po przeliczeniu na PLN kursem NBP z dnia roboczego przed datą kosztu.
 * Sumy liczymy WYŁĄCZNIE z tej kolumny: dotąd nagłówek dodawał CZK i EUR do złotówek
 * jak gdyby nigdy nic (na prodzie 4 FV w CZK + 4 w EUR zawyżały „razem" o ~3,6 tys.).
 * Kurs i jego datę zapisujemy przy rekordzie, żeby kwota w PLN nigdy potem nie drgnęła.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ksef_invoices', function (Blueprint $table) {
            $table->string('kind', 16)->default('invoice')->after('company'); // invoice|zus|vat|cit|oss
            $table->string('contractor_nip', 32)->nullable()->after('contractor');
            $table->unsignedSmallInteger('period_year')->nullable()->after('due_date');
            $table->unsignedTinyInteger('period_month')->nullable()->after('period_year');
            $table->unsignedTinyInteger('period_quarter')->nullable()->after('period_month');
            $table->decimal('amount_pln', 12, 2)->nullable()->after('currency');
            $table->decimal('fx_rate', 12, 6)->nullable()->after('amount_pln');
            $table->date('fx_date')->nullable()->after('fx_rate');

            $table->index(['company', 'kind']);
        });

        // Złotówki przeliczać nie ma po co — kurs 1:1, bez ruszania sieci.
        DB::table('ksef_invoices')->where('currency', 'PLN')->update([
            'amount_pln' => DB::raw('amount'),
            'fx_rate' => 1,
        ]);

        // Waluty obce zostają z NULL — dolicza je `php artisan ksef:fx-fill` (pyta NBP).
    }

    public function down(): void
    {
        Schema::table('ksef_invoices', function (Blueprint $table) {
            $table->dropIndex(['company', 'kind']);
            $table->dropColumn([
                'kind', 'contractor_nip', 'period_year', 'period_month', 'period_quarter',
                'amount_pln', 'fx_rate', 'fx_date',
            ]);
        });
    }
};
