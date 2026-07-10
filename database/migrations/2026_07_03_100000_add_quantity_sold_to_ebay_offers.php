<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * eBay: pole `Quantity` aukcji to ilość ŁĄCZNA (dostępne = Quantity − QuantitySold).
 * Żeby przy zapisie (restock/inline/masowe) ustawić realnie DOSTĘPNĄ ilość, musimy znać
 * QuantitySold. Zapisujemy je przy pobieraniu i doliczamy przy ReviseInventoryStatus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ebay_offers', function (Blueprint $table) {
            $table->integer('quantity_sold')->default(0)->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('ebay_offers', function (Blueprint $table) {
            $table->dropColumn('quantity_sold');
        });
    }
};
