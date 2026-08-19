<?php

namespace App\Http\Controllers\Admin\Roles;

use App\Http\Controllers\Admin\Controller;
use App\Http\Requests\Admin\Roles\DestroyRoleRequest;
use App\Http\Requests\Admin\Roles\IndexRoleRequest;
use App\Http\Requests\Admin\Roles\StoreRoleRequest;
use App\Queries\Filters\FuzzyFilter;
use App\Support\PermissionLabels;
use App\Support\PermissionLockoutGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class RoleController extends Controller
{
    public function index(IndexRoleRequest $request)
    {
        $roles = QueryBuilder::for(Role::class)
            ->allowedFilters([
                AllowedFilter::custom('search', new FuzzyFilter(
                    'id',
                    'name',
                )),
            ])
            ->defaultSort('id')
            ->allowedSorts(['id', 'name'])
            ->with('users')
            // Uwaga na kolejność: select() nadpisuje listę kolumn, więc withCount()
            // musi iść PO nim — inaczej podzapytanie liczące wypada i licznik jest pusty.
            ->select(['id', 'name'])
            ->withCount('permissions')
            ->paginate(request()->get('per_page'))->withQueryString();

        return Inertia::render('Roles/Index', [
            'roles' => $roles,
            'protectedRoles' => self::PROTECTED_ROLES,
        ]);
    }

    /** Role systemowe — nie do skasowania z panelu. */
    private const PROTECTED_ROLES = ['Administrator', 'Guest'];

    public function create()
    {
        $this->authorize('crafter.role.edit');

        return Inertia::render('Roles/Create', [
            'roles' => Role::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(StoreRoleRequest $request)
    {
        $validated = $request->validated();

        $role = Role::create([
            'name' => $validated['name'],
            'guard_name' => 'crafter',
        ]);

        // Nowa rola bez niczego jest bezużyteczna — zawsze dostaje wstęp do panelu.
        $role->givePermissionTo('crafter');

        if (! empty($validated['copy_from_role_id'])) {
            $source = Role::findById((int) $validated['copy_from_role_id'], 'crafter');
            $role->syncPermissions($source->permissions);
        }

        return redirect()
            ->route('crafter.roles.edit', $role->id)
            ->with(['message' => ___('crafter', 'Role has been successfully created')]);
    }

    public function edit(Role $role)
    {
        $this->authorize('crafter.role.edit');

        $allPermissions = Permission::all()->map->name;
        $assignedPermissions = $role->permissions->map->name;

        $permissionsTree = [];

        collect($allPermissions)->each(function ($permission) use (&$permissionsTree, $assignedPermissions) {
            $isAssigned = collect($assignedPermissions)->contains($permission);
            Arr::set($permissionsTree, $permission, $isAssigned);
        });

        return Inertia::render('Roles/Edit', [
            'role' => $role,
            'permissionsTree' => $permissionsTree,
            'permissionLabels' => PermissionLabels::all(),
        ]);
    }

    public function update(Role $role, Request $request)
    {
        $this->authorize('crafter.role.edit');

        $data = $request->validate([
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                Rule::unique('roles', 'name')->where('guard_name', 'crafter')->ignore($role->id),
            ],
            'permissionsTree' => ['required', 'array'],
        ]);

        $newPermissions = collect(Arr::dot($data['permissionsTree']))->filter()->keys()->all();

        PermissionLockoutGuard::assertRoleUpdateSafe($role, $newPermissions);

        if (array_key_exists('name', $data) && ! in_array($role->name, self::PROTECTED_ROLES, true)) {
            $role->update(['name' => $data['name']]);
        }

        $role->syncPermissions($newPermissions);

        return redirect()->back()->with(['message' => ___('crafter', 'Role has been successfully updated')]);
    }

    public function destroy(DestroyRoleRequest $request, Role $role)
    {
        if (in_array($role->name, self::PROTECTED_ROLES, true)) {
            throw ValidationException::withMessages([
                'role' => "Rola „{$role->name}\" jest rolą systemową i nie może zostać usunięta.",
            ]);
        }

        $usersCount = $role->users()->count();

        if ($usersCount > 0) {
            throw ValidationException::withMessages([
                'role' => "Rola „{$role->name}\" jest przypisana do {$usersCount} użytkownik(ów). "
                    . 'Najpierw przepnij ich na inną rolę.',
            ]);
        }

        $role->delete();

        return redirect()
            ->route('crafter.roles.index')
            ->with(['message' => ___('crafter', 'Role has been successfully deleted')]);
    }
}
