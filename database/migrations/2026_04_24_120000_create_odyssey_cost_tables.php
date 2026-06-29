<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('odyssey_cost_months', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->string('label');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['year', 'month']);
        });

        Schema::create('odyssey_cost_order_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('odyssey_cost_month_id')
                ->constrained('odyssey_cost_months')
                ->cascadeOnDelete();
            $table->foreignId('order_id')
                ->constrained('orders')
                ->cascadeOnDelete();
            $table->decimal('cost_goods', 12, 2)->default(0);
            $table->decimal('cost_shipping', 12, 2)->default(0);
            $table->timestamps();

            $table->unique(['odyssey_cost_month_id', 'order_id'], 'ocoe_month_order_unique');
        });

        Schema::create('odyssey_cost_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('odyssey_cost_month_id')
                ->constrained('odyssey_cost_months')
                ->cascadeOnDelete();
            $table->date('paid_at');
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('invoice_number', 64)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('odyssey_cost_payments');
        Schema::dropIfExists('odyssey_cost_order_entries');
        Schema::dropIfExists('odyssey_cost_months');
    }
};
