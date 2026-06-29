<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('base_settings_id')
                ->nullable()
                ->after('customer_id')
                ->constrained('connect_base_settings')
                ->nullOnDelete();
            $table->index('base_settings_id');
        });

        // Backfill: wszystkie istniejące zamówienia -> pierwszy Base (Argo)
        $firstBaseId = DB::table('connect_base_settings')->orderBy('id')->value('id');
        if ($firstBaseId) {
            DB::table('orders')
                ->whereNull('base_settings_id')
                ->update(['base_settings_id' => $firstBaseId]);
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['base_settings_id']);
            $table->dropIndex(['base_settings_id']);
            $table->dropColumn('base_settings_id');
        });
    }
};
