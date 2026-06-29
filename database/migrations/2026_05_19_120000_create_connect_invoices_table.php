<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connect_invoices', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('baselinker_invoice_id')->unique();
            $table->foreignId('order_id')->nullable()
                ->constrained('orders')->nullOnDelete();
            $table->foreignId('base_settings_id')->nullable()
                ->constrained('connect_base_settings')->nullOnDelete();
            $table->unsignedBigInteger('baselinker_order_id')->nullable()->index();

            // Numeracja
            $table->unsignedBigInteger('series_id')->nullable();
            $table->string('series_name', 50)->nullable();
            $table->unsignedBigInteger('nr')->nullable();
            $table->string('nr_full', 100)->nullable()->index();

            // Typ: 'invoice' (faktura) / 'correction' (korekta)
            $table->enum('type', ['invoice', 'correction'])->default('invoice')->index();

            // Dla korekt — FK do faktury zrodlowej (po baselinker_invoice_id, soft reference)
            $table->unsignedBigInteger('corrected_invoice_id')->nullable()->index();

            // Daty
            $table->date('issue_date')->nullable();
            $table->date('sell_date')->nullable();
            $table->date('payment_date')->nullable();

            // Kwoty
            $table->decimal('total_netto', 14, 2)->default(0);
            $table->decimal('total_brutto', 14, 2)->default(0);
            $table->char('currency', 3)->nullable();

            // Meta
            $table->json('raw_payload')->nullable();
            $table->timestamp('imported_at')->nullable();

            $table->timestamps();

            $table->index(['order_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connect_invoices');
    }
};
