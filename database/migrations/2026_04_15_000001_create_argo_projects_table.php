<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Kontener projektu (karta w sidebarze). Każdy projekt ma własny kanban tasków.
        Schema::create('argo_projects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('icon', 32)->nullable();
            $table->string('color', 32)->nullable();
            $table->integer('position')->default(0);
            $table->timestamps();
        });

        // Taski (karty na kanbanie w ramach projektu).
        Schema::create('argo_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('argo_project_id')->constrained('argo_projects')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('kanban_column', [
                'do_zrobienia',
                'w_trakcie',
                'do_zatwierdzenia',
                'done',
                'informacje',
            ])->default('do_zrobienia');
            $table->enum('priority', ['MUST', 'SHOULD', 'COULD', 'WONT', 'CRITICAL'])->nullable();
            $table->json('labels')->nullable();
            $table->date('due_date')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->integer('position')->default(0);
            $table->timestamps();

            $table->index(['argo_project_id', 'kanban_column']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('argo_tasks');
        Schema::dropIfExists('argo_projects');
    }
};
