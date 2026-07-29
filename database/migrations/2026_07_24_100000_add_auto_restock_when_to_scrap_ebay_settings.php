<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Próg auto-restocku. Do tej pory reguła odpalała się przy DOKŁADNIE 0 — a PIM zera prawie
 * nigdy nie widzi (stan świeży tylko w chwili syncu, towar schodzi między syncami), więc
 * praktycznie nie startowała. Teraz: uzupełniaj gdy stan <= auto_restock_when (domyślnie 1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scrap_ebay_settings', function (Blueprint $table) {
            $table->integer('auto_restock_when')->default(1)->after('auto_restock_to');
        });
    }

    public function down(): void
    {
        Schema::table('scrap_ebay_settings', function (Blueprint $table) {
            $table->dropColumn('auto_restock_when');
        });
    }
};
