<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->string('trigger', 20)->default('scheduled'); // scheduled | manual
            $table->enum('status', ['running', 'success', 'error'])->default('running');
            $table->unsignedInteger('orders_fetched')->default(0);
            $table->unsignedInteger('orders_new')->default(0);
            $table->unsignedInteger('orders_updated')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_sync_logs');
    }
};
