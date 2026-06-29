<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('argo_projects', function (Blueprint $table) {
            $table->json('columns')->nullable()->after('color');
            $table->json('labels')->nullable()->after('columns');
            $table->json('priorities')->nullable()->after('labels');
        });

        // Poluzuj enumy — kolumna/priorytet są teraz per-projekt.
        DB::statement("ALTER TABLE argo_tasks MODIFY kanban_column VARCHAR(64) NOT NULL DEFAULT 'do_zrobienia'");
        DB::statement("ALTER TABLE argo_tasks MODIFY priority VARCHAR(32) NULL");

        $defaultColumns = [
            ['key' => 'do_zrobienia',     'name' => 'Do zrobienia',     'color' => 'gray'],
            ['key' => 'w_trakcie',        'name' => 'W trakcie',        'color' => 'blue'],
            ['key' => 'do_zatwierdzenia', 'name' => 'Do zatwierdzenia', 'color' => 'amber'],
            ['key' => 'done',             'name' => 'DONE',             'color' => 'green'],
            ['key' => 'informacje',       'name' => 'Informacje',       'color' => 'purple'],
        ];
        $defaultLabels = [
            ['name' => 'Oferta',     'color' => 'indigo'],
            ['name' => 'IT',         'color' => 'cyan'],
            ['name' => 'Marketing',  'color' => 'pink'],
            ['name' => 'Operacyjne', 'color' => 'orange'],
            ['name' => 'AFERA',      'color' => 'red'],
            ['name' => 'SPRZEDAŻ',   'color' => 'green'],
            ['name' => 'Finanse',    'color' => 'yellow'],
            ['name' => 'HR',         'color' => 'purple'],
        ];
        $defaultPriorities = [
            ['name' => 'CRITICAL', 'color' => 'red'],
            ['name' => 'MUST',     'color' => 'orange'],
            ['name' => 'SHOULD',   'color' => 'amber'],
            ['name' => 'COULD',    'color' => 'blue'],
            ['name' => 'WONT',     'color' => 'gray'],
        ];

        DB::table('argo_projects')->whereNull('columns')->update([
            'columns'    => json_encode($defaultColumns),
            'labels'     => json_encode($defaultLabels),
            'priorities' => json_encode($defaultPriorities),
        ]);
    }

    public function down(): void
    {
        Schema::table('argo_projects', function (Blueprint $table) {
            $table->dropColumn(['columns', 'labels', 'priorities']);
        });

        DB::statement("ALTER TABLE argo_tasks MODIFY kanban_column ENUM('do_zrobienia','w_trakcie','do_zatwierdzenia','done','informacje') NOT NULL DEFAULT 'do_zrobienia'");
        DB::statement("ALTER TABLE argo_tasks MODIFY priority ENUM('MUST','SHOULD','COULD','WONT','CRITICAL') NULL");
    }
};
