<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connect_base_settings', function (Blueprint $table) {
            // 'date_add' (data dodania zamówienia — łapie też niepotwierdzone i potwierdzone wstecznie)
            // 'date_confirmed' (data potwierdzenia przez klienta — pomija niepotwierdzone)
            $table->string('date_filter_type', 20)
                ->default('date_add')
                ->after('sync_from_date');

            $table->boolean('include_archive')
                ->default(false)
                ->after('date_filter_type');

            $table->boolean('include_unconfirmed')
                ->default(true)
                ->after('include_archive');
        });
    }

    public function down(): void
    {
        Schema::table('connect_base_settings', function (Blueprint $table) {
            $table->dropColumn(['date_filter_type', 'include_archive', 'include_unconfirmed']);
        });
    }
};
