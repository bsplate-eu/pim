<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Statystyki pomiaru per kanał konkurenta (Argo Scope → Scrapy → Rumuni).
 * eBay trzyma swoje staty w scrap_ebay_settings; pozostałe kanały (stahl, sklep2, …)
 * tutaj — generycznie, by kafelki monitoringu (nowe/wycofane/ceny) działały dla każdego źródła.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scrap_sources', function (Blueprint $table) {
            $table->id();
            $table->string('source')->unique();        // 'stahl', 'sklep2', …
            $table->string('label')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->integer('last_sync_count')->nullable();
            $table->integer('prev_offer_count')->nullable();
            $table->integer('last_new_count')->nullable();
            $table->integer('last_removed_count')->nullable();
            $table->integer('last_price_up')->nullable();
            $table->integer('last_price_down')->nullable();
            $table->string('last_status')->nullable();  // running | ok | error
            $table->text('last_error')->nullable();
            $table->integer('last_duration_s')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scrap_sources');
    }
};
