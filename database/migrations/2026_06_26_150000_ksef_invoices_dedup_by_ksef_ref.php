<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Realne FV z KSeF deduplikujemy po numerze referencyjnym KSeF (ksef_ref), nie po numerze FV
 * — różni sprzedawcy mogą wystawić faktury o tym samym numerze. Unik (company, number) był błędny.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ksef_invoices', function (Blueprint $table) {
            $table->dropUnique(['company', 'number']);
            $table->unique(['company', 'ksef_ref']); // NULL-e (dane demo) dozwolone wielokrotnie w MySQL
        });
    }

    public function down(): void
    {
        Schema::table('ksef_invoices', function (Blueprint $table) {
            $table->dropUnique(['company', 'ksef_ref']);
            $table->unique(['company', 'number']);
        });
    }
};
