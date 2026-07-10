<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ebay_action_logs', function (Blueprint $table) {
            $table->id();
            $table->string('action', 30)->default('auto_restock'); // typ akcji (na razie tylko auto-restock)
            $table->string('context', 20)->nullable();             // skąd wywołane: cron | manual | sync
            $table->string('status', 20)->default('ok');           // ok | error
            $table->string('marketplace', 20)->nullable();
            $table->string('item_id', 40)->nullable();
            $table->string('sku', 120)->nullable();
            $table->string('title', 255)->nullable();              // migawka tytułu (czytelność listy)
            $table->string('listing_url', 500)->nullable();        // link do aukcji w chwili logu
            $table->unsignedBigInteger('product_id')->nullable();  // zmapowany produkt (jeśli był)
            $table->integer('qty_before')->nullable();             // stan przed (zwykle 0)
            $table->integer('qty_after')->nullable();              // stan po (docelowy; null gdy błąd)
            $table->string('message', 255)->nullable();            // treść błędu lub uwaga
            $table->timestamps();

            $table->index('action', 'idx_ebay_action_log_action');
            $table->index('status', 'idx_ebay_action_log_status');
            $table->index('created_at', 'idx_ebay_action_log_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ebay_action_logs');
    }
};
