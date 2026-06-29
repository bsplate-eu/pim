<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $defaultPermissions = collect([
            // view admin as a whole
            'crafter',

            // manage translations
            'crafter.translation.index',
            'crafter.translation.edit',
            'crafter.translation.rescan',
            'crafter.translation.publish',
            'crafter.translation.export',
            'crafter.translation.import',

            // manage users (access)
            'crafter.admin-user.index',
            'crafter.admin-user.create',
            'crafter.admin-user.show',
            'crafter.admin-user.edit',
            'crafter.admin-user.destroy',
            'crafter.admin-user.impersonal-login',

            // media
            'crafter.media.index',
            'crafter.media.upload',
            'crafter.media.destroy',

            // permissions
            'crafter.role.index',
            'crafter.role.edit',

            // manage tags (access)
            'crafter.tag.index',
            'crafter.tag.store',

            // settings
            'crafter.settings.edit',

            // permissions
            'crafter.permission.index',
            'crafter.permission.edit'
        ]);

        $adminRoleId = DB::table('roles')->insertGetId([
            'name' => 'Administrator',
            'guard_name' => 'crafter',
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $defaultPermissions->each(function ($permission) use ($adminRoleId) {
            $permissionId = DB::table('permissions')->insertGetId([
                'name' => $permission,
                'guard_name' => 'crafter',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
            DB::table('role_has_permissions')->insert([
                'permission_id' => $permissionId,
                'role_id' => $adminRoleId,
            ]);
        });

        // let's create a default Guest role in case self-registration is enabled
        $guestRoleId = DB::table('roles')->insertGetId([
            'name' => 'Guest',
            'guard_name' => 'crafter',
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
        DB::table('role_has_permissions')->insert([
            'permission_id' => DB::table('permissions')
                ->where('name', '=', 'crafter')
                ->where('guard_name', '=', 'crafter')
                ->value('id'),
            'role_id' => $guestRoleId,
        ]);

        app()['cache']->forget(config('permission.cache.key'));
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        $guestRole = DB::table('roles')->where('name', 'Guest')->where('guard_name', 'crafter')->first();
        DB::table('role_has_permissions')
            ->where('role_id', $guestRole->id)
            ->delete();
        DB::table('roles')->where('id', $guestRole->id)->delete();

        $adminRole = DB::table('roles')->where('name', 'Administrator')->where('guard_name', 'crafter')->first();
        DB::table('role_has_permissions')
            ->where('role_id', $adminRole->id)
            ->delete();
        DB::table('roles')->where('id', $adminRole->id)->delete();

        $this->defaultPermissions->each(function ($permission){
            $permissionItem = DB::table('permissions')->where([
                'name' => $permission,
                'guard_name' => 'crafter'
            ])->first();

            if ($permissionItem !== null) {
                DB::table('permissions')->where('id', $permissionItem->id)->delete();
                DB::table('model_has_permissions')->where('permission_id', $permissionItem->id)->delete();
            }
        });
        app()['cache']->forget(config('permission.cache.key'));
    }
};
