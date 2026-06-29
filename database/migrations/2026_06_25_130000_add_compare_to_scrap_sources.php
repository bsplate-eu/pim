<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cennik porównawczy/docelowy + VAT PER KANAŁ (scrap_sources), zamiast globalnie w scrap_ebay_settings.
 * Każdy tab (Ebay / Niemcy / Sklep 2) ma własne cenniki. Backfill ebay z dotychczasowych globalnych ustawień.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scrap_sources', function (Blueprint $table) {
            $table->foreignId('compare_pricelist_id')->nullable()->after('label')->constrained('pricelists')->nullOnDelete();
            $table->decimal('compare_vat', 5, 2)->nullable()->after('compare_pricelist_id');
            $table->foreignId('target_pricelist_id')->nullable()->after('compare_vat')->constrained('pricelists')->nullOnDelete();
        });

        // Backfill: przenieś dotychczasowe globalne ustawienia eBay → scrap_sources[ebay].
        $s = DB::table('scrap_ebay_settings')->first();
        DB::table('scrap_sources')->updateOrInsert(
            ['source' => 'ebay'],
            [
                'label' => 'eBay',
                'compare_pricelist_id' => $s->compare_pricelist_id ?? null,
                'compare_vat' => $s->compare_vat ?? null,
                'target_pricelist_id' => $s->target_pricelist_id ?? null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        Schema::table('scrap_sources', function (Blueprint $table) {
            $table->dropConstrainedForeignId('compare_pricelist_id');
            $table->dropColumn('compare_vat');
            $table->dropConstrainedForeignId('target_pricelist_id');
        });
    }
};
