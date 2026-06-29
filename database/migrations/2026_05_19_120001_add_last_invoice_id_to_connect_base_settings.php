<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connect_base_settings', function (Blueprint $table) {
            $table->unsignedBigInteger('last_invoice_id')->nullable()->after('last_journal_id');
            $table->timestamp('last_invoice_sync_at')->nullable()->after('last_invoice_id');
        });
    }

    public function down(): void
    {
        Schema::table('connect_base_settings', function (Blueprint $table) {
            $table->dropColumn(['last_invoice_id', 'last_invoice_sync_at']);
        });
    }
};
