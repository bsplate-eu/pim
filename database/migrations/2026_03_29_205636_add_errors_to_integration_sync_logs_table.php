<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_sync_logs', function (Blueprint $table) {
            $table->json('errors')->nullable()->after('message');
            $table->unsignedInteger('error_count')->default(0)->after('errors');
        });
    }

    public function down(): void
    {
        Schema::table('integration_sync_logs', function (Blueprint $table) {
            $table->dropColumn(['errors', 'error_count']);
        });
    }
};
