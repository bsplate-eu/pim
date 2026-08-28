<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Uprawnienie modulowe dla Argo PIM → Produkcja.
 *
 * Wzorzec jak w 2026_08_19_100000_add_module_permissions.php: uprawnienie dostaja
 * wszystkie role poza „Guest", zebym nikomu nie zabrac dostepu przy wdrozeniu.
 * Zawezanie robi sie potem w panelu: Ustawienia systemu → Uprawnienia.
 */
return new class extends Migration
{
    private string $permission = 'crafter.module.production';

    public function up(): void
    {
        $now = Carbon::now();

        $permissionId = DB::table('permissions')
            ->where('name', $this->permission)
            ->where('guard_name', 'crafter')
            ->value('id');

        if ($permissionId === null) {
            $permissionId = DB::table('permissions')->insertGetId([
                'name' => $this->permission,
                'guard_name' => 'crafter',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $roleIds = DB::table('roles')
            ->where('guard_name', 'crafter')
            ->where('name', '<>', 'Guest')
            ->pluck('id');

        foreach ($roleIds as $roleId) {
            $alreadyGranted = DB::table('role_has_permissions')
                ->where('permission_id', $permissionId)
                ->where('role_id', $roleId)
                ->exists();

            if (! $alreadyGranted) {
                DB::table('role_has_permissions')->insert([
                    'permission_id' => $permissionId,
                    'role_id' => $roleId,
                ]);
            }
        }

        app()['cache']->forget(config('permission.cache.key'));
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')
            ->where('name', $this->permission)
            ->where('guard_name', 'crafter')
            ->value('id');

        if ($permissionId === null) {
            return;
        }

        DB::table('role_has_permissions')->where('permission_id', $permissionId)->delete();
        DB::table('model_has_permissions')->where('permission_id', $permissionId)->delete();
        DB::table('permissions')->where('id', $permissionId)->delete();

        app()['cache']->forget(config('permission.cache.key'));
    }
};
