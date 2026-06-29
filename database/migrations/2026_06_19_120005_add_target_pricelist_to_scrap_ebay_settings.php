<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Etap 3 — cennik docelowy: tam trafiają zatwierdzone ceny eBay.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scrap_ebay_settings', function (Blueprint $table) {
            $table->foreignId('target_pricelist_id')->nullable()->after('compare_vat')->constrained('pricelists')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('scrap_ebay_settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('target_pricelist_id');
        });
    }
};
