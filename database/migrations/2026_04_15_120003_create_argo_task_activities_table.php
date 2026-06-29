<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('argo_task_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('argo_task_id')->constrained('argo_tasks')->cascadeOnDelete();
            $table->foreignId('admin_user_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->string('action', 50);
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['argo_task_id', 'created_at']);
            $table->index(['argo_task_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('argo_task_activities');
    }
};
