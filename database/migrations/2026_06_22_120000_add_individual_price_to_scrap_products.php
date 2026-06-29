<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cena indywidualna (ręczna) oferty — Argo Scope → Rumuni.
 * Gdy ustawiona (> 0), to ONA — nie cena źródła (eBay) — trafia do cennika docelowego.
 * Pusta / 0 = brak wpływu (działa cena źródła jak dotąd).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scrap_products', function (Blueprint $table) {
            $table->decimal('individual_price', 10, 2)->nullable()->after('price');
        });
    }

    public function down(): void
    {
        Schema::table('scrap_products', function (Blueprint $table) {
            $table->dropColumn('individual_price');
        });
    }
};
