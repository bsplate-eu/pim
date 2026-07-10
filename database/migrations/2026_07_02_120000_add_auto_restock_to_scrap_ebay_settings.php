<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automatyczne akcje eBay — reguła „auto-restock": gdy stan aukcji = 0, ustaw na wartość docelową.
 * Przełącznik + wartość docelowa trzymane w scrap_ebay_settings (jedna reguła; kolejne dołożymy tu).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scrap_ebay_settings', function (Blueprint $table) {
            $table->boolean('auto_restock_enabled')->default(true)->after('target_pricelist_id');
            $table->integer('auto_restock_to')->default(5)->after('auto_restock_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('scrap_ebay_settings', function (Blueprint $table) {
            $table->dropColumn(['auto_restock_enabled', 'auto_restock_to']);
        });
    }
};
