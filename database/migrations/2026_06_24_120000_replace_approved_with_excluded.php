<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * „Zatwierdź" → „Wyklucz" (Argo Scope → Rumuni). Odwrócenie logiki:
 * excluded = true → narzędzie NIE rusza tej pozycji (w cenniku zostaje oryginalna cena).
 * Domyślnie false = pozycja jest aktualizowana. Zastępuje wcześniejsze `approved`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('scrap_products', 'approved')) {
            Schema::table('scrap_products', function (Blueprint $table) {
                $table->dropColumn('approved');
            });
        }
        if (! Schema::hasColumn('scrap_products', 'excluded')) {
            Schema::table('scrap_products', function (Blueprint $table) {
                $table->boolean('excluded')->default(false)->after('individual_price');
            });
        }
    }

    public function down(): void
    {
        Schema::table('scrap_products', function (Blueprint $table) {
            $table->dropColumn('excluded');
            $table->boolean('approved')->nullable()->after('individual_price');
        });
    }
};
