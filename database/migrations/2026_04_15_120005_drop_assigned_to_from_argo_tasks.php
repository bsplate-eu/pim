<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('argo_tasks', 'assigned_to')) {
            return;
        }

        Schema::table('argo_tasks', function (Blueprint $table) {
            // Drop FK if present (name może być auto-generowany)
            try {
                $table->dropForeign(['assigned_to']);
            } catch (\Throwable $e) {
                // ignore — FK może nie istnieć
            }
            $table->dropColumn('assigned_to');
        });
    }

    public function down(): void
    {
        Schema::table('argo_tasks', function (Blueprint $table) {
            $table->foreignId('assigned_to')->nullable()->after('due_date')->constrained('admin_users')->nullOnDelete();
        });
    }
};
