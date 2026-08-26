<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * „Wyrównaj cenę" — Argo Scope → Rumuni.
 * Oferta wyrównana = nasza cena ma być RÓWNA cenie konkurenta (1:1), także gdy nasza jest niższa.
 * Flaga wyłącza dla tej pozycji regułę „niższa z dwóch" (oferta ↔ cennik porównawczy)
 * w ScopeRumuniController::targetNetPrice() — bez niej wyrównanie w górę nigdy by nie weszło do cennika.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scrap_products', function (Blueprint $table) {
            $table->boolean('aligned')->default(false)->after('individual_price');
        });
    }

    public function down(): void
    {
        Schema::table('scrap_products', function (Blueprint $table) {
            $table->dropColumn('aligned');
        });
    }
};
