<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('argo_task_assignees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('argo_task_id')->constrained('argo_tasks')->cascadeOnDelete();
            $table->foreignId('admin_user_id')->constrained('admin_users')->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('assigned_at')->useCurrent();
            $table->timestamps();

            $table->unique(['argo_task_id', 'admin_user_id'], 'argo_task_assignees_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('argo_task_assignees');
    }
};
