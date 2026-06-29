<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('argo_tasks', 'assigned_to')) {
            return;
        }

        $now = now();

        DB::table('argo_tasks')
            ->whereNotNull('assigned_to')
            ->orderBy('id')
            ->chunkById(500, function ($tasks) use ($now) {
                $rows = [];
                foreach ($tasks as $task) {
                    $rows[] = [
                        'argo_task_id'  => $task->id,
                        'admin_user_id' => $task->assigned_to,
                        'assigned_by'   => null,
                        'assigned_at'   => $now,
                        'created_at'    => $now,
                        'updated_at'    => $now,
                    ];
                }
                if ($rows) {
                    DB::table('argo_task_assignees')->insertOrIgnore($rows);
                }
            });
    }

    public function down(): void
    {
        // backfill — brak rollbacku (dane pozostają w pivocie)
    }
};
