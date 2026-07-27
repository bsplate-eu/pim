<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Zakup reczny (EUR) — twardy override ceny zakupu wpisywany z palca w gridzie
            // cennika. Gdy > 0 jest NADRZEDNY nad cena zakupu z cennika bazowego (slug
            // 'sumpguard'). Wartosc globalna dla produktu, wiec widoczna we wszystkich
            // cennikach. 0 = brak override, liczymy z cennika bazowego.
            $table->decimal('purchase_price_manual', 10, 2)->default(0)->after('weight');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('purchase_price_manual');
        });
    }
};
