<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_sync_logs', function (Blueprint $table) {
            $table->foreignId('base_settings_id')
                ->nullable()
                ->after('id')
                ->constrained('connect_base_settings')
                ->nullOnDelete();
            $table->index('base_settings_id');
        });
    }

    public function down(): void
    {
        Schema::table('order_sync_logs', function (Blueprint $table) {
            $table->dropForeign(['base_settings_id']);
            $table->dropIndex(['base_settings_id']);
            $table->dropColumn('base_settings_id');
        });
    }
};
