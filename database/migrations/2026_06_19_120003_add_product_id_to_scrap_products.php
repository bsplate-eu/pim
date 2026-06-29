<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mapowanie oferty konkurenta ↔ nasz produkt (Argo Scope, Etap 1).
 * product_id = nasz Product; match_type = 'auto' (po kodzie/EAN) lub 'manual' (z listy).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scrap_products', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable()->after('seller')->constrained('products')->nullOnDelete();
            $table->string('match_type', 16)->nullable()->after('product_id'); // 'auto' | 'manual'
        });
    }

    public function down(): void
    {
        Schema::table('scrap_products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_id');
            $table->dropColumn('match_type');
        });
    }
};
