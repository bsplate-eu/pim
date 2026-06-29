<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Monitoring konkurencji (Argo Scope):
 *  - scrap_changes: log zmian wykrytych przy każdym pomiarze (nowe / wycofane / cena ↑↓),
 *  - statystyki ostatniego pomiaru w scrap_ebay_settings (do kafelków w UI).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scrap_changes', function (Blueprint $table) {
            $table->id();
            $table->string('source')->index();              // 'ebay' | 'stahl' ...
            $table->string('type')->index();                // 'new' | 'removed' | 'price_up' | 'price_down'
            $table->string('external_id');
            $table->string('title')->nullable();
            $table->string('herstellernummer')->nullable();
            $table->decimal('old_price', 10, 2)->nullable();
            $table->decimal('new_price', 10, 2)->nullable();
            $table->timestamp('detected_at')->index();
            $table->timestamps();
        });

        Schema::table('scrap_ebay_settings', function (Blueprint $table) {
            $table->unsignedInteger('prev_offer_count')->nullable()->after('last_sync_count');
            $table->unsignedInteger('last_new_count')->nullable()->after('prev_offer_count');
            $table->unsignedInteger('last_removed_count')->nullable()->after('last_new_count');
            $table->unsignedInteger('last_price_up')->nullable()->after('last_removed_count');
            $table->unsignedInteger('last_price_down')->nullable()->after('last_price_up');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scrap_changes');
        Schema::table('scrap_ebay_settings', function (Blueprint $table) {
            $table->dropColumn(['prev_offer_count', 'last_new_count', 'last_removed_count', 'last_price_up', 'last_price_down']);
        });
    }
};
