<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NASZE oferty (aukcje) na eBay — pobierane przez Sell/Trading API z konta sprzedawcy.
 * Osobny byt od scrap_products (oferty konkurencji). Wiersz = wariant: jedno multi-variation
 * listing ma wiele SKU/cen, więc klucz to (item_id, sku, marketplace) — gotowe na wiele rynków.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ebay_offers', function (Blueprint $table) {
            $table->id();
            $table->string('item_id');                       // eBay listing ID
            $table->string('sku')->default('');              // SKU wariantu ('' dla oferty bez wariantów)
            $table->string('marketplace')->default('EBAY_DE'); // rynek (EBAY_DE, EBAY_PL, EBAY_US…)
            $table->string('title')->nullable();
            $table->decimal('price', 12, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->integer('quantity')->nullable();
            $table->string('listing_status')->nullable();    // Active / Ended / Completed…
            $table->string('listing_url')->nullable();
            $table->json('variation')->nullable();           // specyfika wariantu (np. {"Größe":"M"})
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('match_type')->nullable();        // auto / manual
            $table->json('raw')->nullable();
            $table->timestamp('first_seen')->nullable();
            $table->timestamp('last_seen')->nullable();
            $table->timestamps();

            $table->unique(['item_id', 'sku', 'marketplace']);
            $table->index('product_id');
            $table->index('marketplace');
            $table->index('sku');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ebay_offers');
    }
};
