<?php

namespace App\Support;

use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * Zabezpieczenie przed zatrzaśnięciem sobie drzwi.
 *
 * Ekran „Uprawnienia" pozwala odznaczyć dowolny checkbox — łącznie z prawem do
 * zarządzania uprawnieniami. Jeden klik i nikt (łącznie z autorem kliku) nie może
 * już wejść w role. Poprzednio pilnował tego wyłącznie `confirm()` w JS, czyli nic.
 *
 * Zasada: użytkownik zapisujący zmiany musi PO ZAPISIE nadal mieć komplet uprawnień
 * do zarządzania rolami. Odebranie ich komuś innemu jest dozwolone.
 */
class PermissionLockoutGuard
{
    /** Uprawnienia, bez których nie da się już nic odkręcić z panelu. */
    public const CRITICAL = [
        'crafter.role.index',
        'crafter.role.edit',
        'crafter.permission.index',
        'crafter.permission.edit',
    ];

    /**
     * @param  array<int, array<int, string>>  $newPermissionsByRoleId
     *         mapa id_roli => docelowa lista nazw uprawnień (tylko role, które zmieniasz)
     *
     * @throws ValidationException
     */
    public static function assertStillManageable(array $newPermissionsByRoleId): void
    {
        $user = Auth::user();

        if ($user === null) {
            return;
        }

        $resulting = collect($user->getDirectPermissions())->map->name;

        foreach ($user->roles as $role) {
            $resulting = $resulting->merge(
                array_key_exists($role->id, $newPermissionsByRoleId)
                    ? $newPermissionsByRoleId[$role->id]
                    : $role->permissions->map->name->all()
            );
        }

        $lost = array_diff(self::CRITICAL, $resulting->unique()->all());

        if ($lost !== []) {
            throw ValidationException::withMessages([
                'permissionsTree' => 'Ta zmiana odebrałaby Tobie prawo do zarządzania uprawnieniami ('
                    . implode(', ', $lost)
                    . ') — nikt nie mógłby jej już cofnąć z panelu. Zmiana nie została zapisana.',
            ]);
        }
    }

    /**
     * Skrót dla ekranu edycji jednej roli.
     *
     * @param  array<int, string>  $newPermissions
     *
     * @throws ValidationException
     */
    public static function assertRoleUpdateSafe(Role $role, array $newPermissions): void
    {
        self::assertStillManageable([$role->id => $newPermissions]);
    }
}
