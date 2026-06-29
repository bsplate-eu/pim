<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connect_base_settings', function (Blueprint $table) {
            $table->id();
            $table->text('api_key')->nullable(); // encrypted (Laravel Crypt)
            $table->boolean('enabled')->default(false);
            $table->timestamp('sync_from_date')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->unsignedBigInteger('last_sync_order_id')->nullable();
            $table->unsignedBigInteger('last_journal_id')->nullable();
            $table->unsignedInteger('sync_interval_minutes')->default(15);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connect_base_settings');
    }
};
