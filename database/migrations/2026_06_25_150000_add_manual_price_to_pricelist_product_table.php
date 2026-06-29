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
            // Cena reczna — twardy override wpisywany recznie. Gdy > 0 jest CENA EKSPORTOWA
            // (nadpisuje "Cena sprzedazy netto"/price we wszystkich sciezkach wysylki: sync,
            // BaseLinker, Prestashop/Selly, CSV). Pusta/0 = brak override, eksport bierze price.
            $table->decimal('manual_price', 10, 2)->default(0)->after('auto_price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pricelist_product', function (Blueprint $table) {
            $table->dropColumn('manual_price');
        });
    }
};
