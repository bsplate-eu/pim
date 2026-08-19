<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Uprawnienia modułowe dla części Argo (HQ / Connect / Scope / Task / Mail / Tłumaczenia).
 *
 * Te moduły powstały po Crafterze i do tej pory nie miały ŻADNEJ kontroli dostępu —
 * wystarczyło być zalogowanym. Egzekwuje je App\Http\Middleware\EnsureModuleAccess
 * wg mapy w config/module-permissions.php.
 *
 * Migracja nadaje komplet nowych uprawnień WSZYSTKIM istniejącym rolom poza „Guest",
 * żeby nikomu nie zabrać dostępu w momencie wdrożenia. Zawężanie robi się potem
 * w panelu: Ustawienia systemu → Uprawnienia.
 */
return new class extends Migration
{
    /** @var array<string,string> nazwa uprawnienia => opis dla panelu */
    private array $permissions = [
        'crafter.module.costs'        => 'Argo HQ · Koszty (planer, zestawienia, raporty, wyciągi)',
        'crafter.module.kasa'         => 'Argo HQ · Kasa',
        'crafter.module.ksef'         => 'Argo HQ · KSeF (faktury Pareto / BSP)',
        'crafter.module.connect'      => 'Argo Connect (zamówienia, klienci, mapa, integracje)',
        'crafter.module.marketplace'  => 'Argo Connect · Marketplace eBay (oferty, kategorie, wystawianie)',
        'crafter.module.scope'        => 'Argo Scope (scrapy konkurencji)',
        'crafter.module.task'         => 'Argo Task (projekty i zadania)',
        'crafter.module.mail'         => 'Argo Mail (skrzynka, konta, administrator AI)',
        'crafter.module.translations' => 'Tłumaczenia (matryca, review, logi, ustawienia)',
    ];

    public function up(): void
    {
        $now = Carbon::now();

        $roleIds = DB::table('roles')
            ->where('guard_name', 'crafter')
            ->where('name', '<>', 'Guest')
            ->pluck('id');

        foreach (array_keys($this->permissions) as $permission) {
            $permissionId = DB::table('permissions')
                ->where('name', $permission)
                ->where('guard_name', 'crafter')
                ->value('id');

            if ($permissionId === null) {
                $permissionId = DB::table('permissions')->insertGetId([
                    'name' => $permission,
                    'guard_name' => 'crafter',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

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
        }

        app()['cache']->forget(config('permission.cache.key'));
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')
            ->whereIn('name', array_keys($this->permissions))
            ->where('guard_name', 'crafter')
            ->pluck('id');

        DB::table('role_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();

        app()['cache']->forget(config('permission.cache.key'));
    }
};
