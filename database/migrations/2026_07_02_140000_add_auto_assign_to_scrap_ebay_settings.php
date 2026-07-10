<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automatyczne akcje eBay — reguła „auto-przypisanie": mapuj nieprzypisane oferty do naszych
 * produktów po SKU (ebay_offers.sku ↔ Product.product_code). Przełącznik w scrap_ebay_settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scrap_ebay_settings', function (Blueprint $table) {
            $table->boolean('auto_assign_enabled')->default(true)->after('auto_restock_to');
        });
    }

    public function down(): void
    {
        Schema::table('scrap_ebay_settings', function (Blueprint $table) {
            $table->dropColumn('auto_assign_enabled');
        });
    }
};
