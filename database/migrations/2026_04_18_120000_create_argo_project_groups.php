<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Tabela grup projektów.
        Schema::create('argo_project_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('icon', 32)->nullable();
            $table->string('color', 32)->nullable();
            $table->integer('position')->default(0);
            $table->timestamps();
        });

        // 2. Dodaj FK do argo_projects — najpierw nullable, żeby backfill mógł ustawić wartość.
        Schema::table('argo_projects', function (Blueprint $table) {
            $table->foreignId('argo_project_group_id')
                ->nullable()
                ->after('id')
                ->constrained('argo_project_groups')
                ->cascadeOnDelete();
        });

        // 3. Backfill: utwórz grupę "Ogólne" jeśli są istniejące projekty bez grupy.
        $hasOrphans = DB::table('argo_projects')->whereNull('argo_project_group_id')->exists();
        if ($hasOrphans) {
            $defaultGroupId = DB::table('argo_project_groups')->insertGetId([
                'name'        => 'Ogólne',
                'description' => 'Domyślna grupa — utworzona automatycznie podczas migracji do hierarchii grup.',
                'icon'        => null,
                'color'       => 'gray',
                'position'    => 0,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);

            DB::table('argo_projects')
                ->whereNull('argo_project_group_id')
                ->update(['argo_project_group_id' => $defaultGroupId]);
        }

        // 4. Teraz wymuś NOT NULL.
        DB::statement('ALTER TABLE argo_projects MODIFY argo_project_group_id BIGINT UNSIGNED NOT NULL');
    }

    public function down(): void
    {
        Schema::table('argo_projects', function (Blueprint $table) {
            $table->dropForeign(['argo_project_group_id']);
            $table->dropColumn('argo_project_group_id');
        });

        Schema::dropIfExists('argo_project_groups');
    }
};
