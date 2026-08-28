<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dane produkcyjne trzymane per KOD, nie per produkt.
 *
 * W PIM ten sam `product_code` powtarza sie dla kazdego auta, do ktorego czesc
 * pasuje (18.201 = 21 wpisow), a produkcja robi jedna sztuke — wiec kluczem jest
 * kod, a nie `products.id`. Tabela jest miejscem na kolejne kolumny produkcyjne;
 * na start trzyma tylko znacznik „projekt".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_items', function (Blueprint $table) {
            $table->id();
            $table->string('product_code')->unique();
            $table->boolean('has_project')->default(false)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_items');
    }
};
