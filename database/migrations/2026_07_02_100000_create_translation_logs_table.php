<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('translation_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('external_id', 60)->nullable();   // kopia — przetrwa usunięcie produktu
            $table->string('product_code', 120)->nullable(); // SKU
            $table->string('name_pl', 255)->nullable();      // nazwa PL w chwili logu (czytelność listy)
            $table->string('action', 20)->default('auto_matrix'); // typ operacji
            $table->string('context', 30)->nullable();       // skąd wywołane: review/bulk/command/script/auto
            $table->string('status', 20)->default('ok');     // ok | unmatched | error | skipped
            $table->boolean('matched')->default(false);      // czy fraza rozpoznana w matrycy
            $table->string('source_locale', 10)->default('pl'); // język źródłowy komponowania
            $table->json('changes')->nullable();             // [{locale, from, to}] — PRZED→PO per locale
            $table->json('stats')->nullable();               // {applied_locales, applied_integrations, skipped_locked}
            $table->string('message', 255)->nullable();
            $table->timestamps();

            $table->index('product_id', 'idx_translation_log_product');
            $table->index('status', 'idx_translation_log_status');
            $table->index('created_at', 'idx_translation_log_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translation_logs');
    }
};
