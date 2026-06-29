<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_products', function (Blueprint $table) {
            $table->string('payload_hash', 64)->nullable()->after('external_id');
            $table->string('state', 20)->default('pending')->after('payload_hash');
            $table->index(['integration_id', 'state'], 'idx_ip_state');
        });

        // Seed state from existing data
        DB::table('integration_products')
            ->whereNotNull('synced_at')
            ->update(['state' => 'synced']);
    }

    public function down(): void
    {
        Schema::table('integration_products', function (Blueprint $table) {
            $table->dropIndex('idx_ip_state');
            $table->dropColumn(['payload_hash', 'state']);
        });
    }
};
