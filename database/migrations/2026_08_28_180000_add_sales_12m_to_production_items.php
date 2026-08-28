<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprzedaz z ostatnich 12 miesiecy per kod produkcyjny.
 *
 * Wartosc wgrywana z raportu Subiekta („Sprzedaz wg asortymentu"), nie liczona
 * w locie — PIM nie ma danych sprzedazowych, sa w Subiekcie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_items', function (Blueprint $table) {
            $table->unsignedInteger('sales_12m')->default(0)->after('has_project');
        });
    }

    public function down(): void
    {
        Schema::table('production_items', function (Blueprint $table) {
            $table->dropColumn('sales_12m');
        });
    }
};
