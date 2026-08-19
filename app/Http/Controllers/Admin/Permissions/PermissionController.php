<?php

namespace App\Http\Controllers\Admin\Permissions;

use App\Http\Controllers\Admin\Controller;
use App\Http\Requests\Admin\Permissions\IndexPermissionRequest;
use App\Http\Requests\Admin\Permissions\UpdatePermissionRequest;
use App\Support\PermissionLabels;
use App\Support\PermissionLockoutGuard;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class PermissionController extends Controller
{
    public function index(IndexPermissionRequest $request)
    {
        // column with permissions names
        $allPermissions = Permission::all()->map->name;
        $permissionsTree = [];

        collect($allPermissions)->each(function ($permission) use (&$permissionsTree) {
            Arr::set($permissionsTree, $permission, $permission);
        });

        // column for roles
        $rolesPermissions = Role::with('permissions')->withCount('users')->get();

        $roleTree = $rolesPermissions->map(function ($role) {
            return [
                'id' => $role['id'],
                'name' => $role['name'],
                'users_count' => $role->users_count,
                'permissions' => $role->permissions->map->name,
            ];
        });


        return Inertia::render('Permissions/Index', [
            'roles' => $roleTree,
            'permissions' => $permissionsTree,
            'permissionLabels' => PermissionLabels::all(),
        ]);
    }

    public function update(UpdatePermissionRequest $request)
    {
        $validated = $request->validated();

        $newPermissionsByRoleId = collect($validated['roles'])
            ->mapWithKeys(fn ($role) => [(int) $role['id'] => array_values($role['permissions'] ?? [])])
            ->all();

        // Nie pozwól zapisać zmiany, która odbiera autorowi prawo do zarządzania
        // uprawnieniami — z panelu nie dałoby się już tego cofnąć.
        PermissionLockoutGuard::assertStillManageable($newPermissionsByRoleId);

        collect($validated['roles'])->each(function ($role) {
            $currentRole = Role::find($role['id']);

            $currentRole->syncPermissions($role['permissions']);
        });

        return redirect()->back()->with(['message' => ___('crafter', 'Permissions have been successfully updated')]);
    }
}
