<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Widoczność skrzynek Argo Mail per użytkownik.
 *
 * Do tej pory każdy, kto wszedł w Argo Mail, widział WSZYSTKIE wpięte skrzynki.
 * Teraz obowiązuje zasada:
 *   - rola z uprawnieniem `crafter.mail-account.all` → widzi wszystkie skrzynki,
 *   - bez tego uprawnienia → widzi wyłącznie skrzynki przypisane mu imiennie
 *     w tej tabeli (Użytkownicy → edycja → „Skrzynki Argo Mail").
 *
 * Uwaga na wdrożeniu: uprawnienie „wszystkie skrzynki" dostają wszystkie
 * istniejące role poza „Guest", żeby nikomu nie zniknęła poczta w dniu wdrożenia.
 * Zawężanie robi się świadomie: odbierz roli to uprawnienie i przypisz skrzynki.
 */
return new class extends Migration
{
    private const PERMISSION = 'crafter.mail-account.all';

    public function up(): void
    {
        Schema::create('mail_account_admin_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mail_account_id')->constrained('mail_accounts')->cascadeOnDelete();
            $table->foreignId('admin_user_id')->constrained('admin_users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['mail_account_id', 'admin_user_id'], 'mail_account_admin_user_unique');
        });

        $now = Carbon::now();

        $permissionId = DB::table('permissions')
            ->where('name', self::PERMISSION)
            ->where('guard_name', 'crafter')
            ->value('id');

        if ($permissionId === null) {
            $permissionId = DB::table('permissions')->insertGetId([
                'name' => self::PERMISSION,
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
            $exists = DB::table('role_has_permissions')
                ->where('permission_id', $permissionId)
                ->where('role_id', $roleId)
                ->exists();

            if (! $exists) {
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
        Schema::dropIfExists('mail_account_admin_user');

        $permissionId = DB::table('permissions')
            ->where('name', self::PERMISSION)
            ->where('guard_name', 'crafter')
            ->value('id');

        if ($permissionId !== null) {
            DB::table('role_has_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('model_has_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }

        app()['cache']->forget(config('permission.cache.key'));
    }
};
