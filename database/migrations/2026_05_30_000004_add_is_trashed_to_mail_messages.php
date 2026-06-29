<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_messages', function (Blueprint $table) {
            $table->boolean('is_trashed')->default(false)->after('is_flagged');
            $table->timestamp('trashed_at')->nullable()->after('is_trashed');
            $table->index('is_trashed');
        });
    }

    public function down(): void
    {
        Schema::table('mail_messages', function (Blueprint $table) {
            $table->dropColumn(['is_trashed', 'trashed_at']);
        });
    }
};
