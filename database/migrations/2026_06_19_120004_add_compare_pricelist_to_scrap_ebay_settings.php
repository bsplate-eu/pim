<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Etap 2 — cennik porównawczy w karcie scrapu.
 * compare_pricelist_id = nasz cennik do porównania; compare_vat = stawka VAT (%) do przeliczenia netto→brutto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scrap_ebay_settings', function (Blueprint $table) {
            $table->foreignId('compare_pricelist_id')->nullable()->after('keyword')->constrained('pricelists')->nullOnDelete();
            $table->decimal('compare_vat', 5, 2)->nullable()->after('compare_pricelist_id');
        });
    }

    public function down(): void
    {
        Schema::table('scrap_ebay_settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('compare_pricelist_id');
            $table->dropColumn('compare_vat');
        });
    }
};
