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
        Schema::table('pricelists', function (Blueprint $table) {
            // Mnoznik/wzor cenowy per cennik (np. "250" dla %, "2,5 * 1,2" dla mnoznika).
            // Cena sprzedazy = cena zakupu (EUR) x mnoznik, potem ew. przeliczenie EUR -> waluta.
            $table->string('price_formula')->nullable();
            // Tryb mnoznika: 'percent' (wartosc w %) lub 'multiply' (mnoznik/wzor).
            $table->string('price_formula_mode')->default('multiply');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pricelists', function (Blueprint $table) {
            $table->dropColumn(['price_formula', 'price_formula_mode']);
        });
    }
};
