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
        Schema::table('pricelist_product', function (Blueprint $table) {
            // Cena netto "automatyczna" — wynik Operacji masowych (wylicz z ceny zakupu,
            // % zmiana, przeliczenie waluty). "Cena sprzedazy netto" (price) jest reczna,
            // autorytatywna i NIE jest ruszana przez operacje masowe; auto_price przepisuje
            // sie do price osobnym przyciskiem.
            $table->decimal('auto_price', 10, 2)->default(0)->after('price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pricelist_product', function (Blueprint $table) {
            $table->dropColumn('auto_price');
        });
    }
};
