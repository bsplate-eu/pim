<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bramka modułowa dla tras Argo (HQ / Connect / Scope / Task / Mail / Tłumaczenia).
 *
 * Moduły dobudowane po Crafterze nie mają klas Request z Gate::allows — zamiast
 * dopisywać bramkę do każdego kontrolera z osobna, ta warstwa mapuje nazwę trasy
 * na uprawnienie wg config/module-permissions.php.
 *
 * Zasady:
 *  - brak zalogowanego użytkownika  → przepuszczamy (od tego jest middleware 'auth'),
 *  - trasa bez nazwy / bez dopasowania → przepuszczamy (stare moduły mają własne bramki),
 *  - dopasowanie + brak uprawnienia → 403.
 */
class EnsureModuleAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        $permission = $this->permissionForRoute($request->route()?->getName());

        if ($permission !== null && ! $user->can($permission)) {
            abort(403, 'Brak uprawnień do tego modułu.');
        }

        return $next($request);
    }

    /**
     * Zwraca uprawnienie wymagane dla danej nazwy trasy albo null.
     * Wygrywa najdłuższy pasujący prefiks (connect.marketplace. > connect.).
     */
    protected function permissionForRoute(?string $routeName): ?string
    {
        if ($routeName === null || ! str_starts_with($routeName, 'crafter.')) {
            return null;
        }

        $name = substr($routeName, strlen('crafter.'));

        foreach (config('module-permissions.except', []) as $excluded) {
            if (str_starts_with($name, $excluded)) {
                return null;
            }
        }

        $match = null;
        $matchLength = -1;

        foreach (config('module-permissions.map', []) as $prefix => $permission) {
            if (str_starts_with($name, $prefix) && strlen($prefix) > $matchLength) {
                $match = $permission;
                $matchLength = strlen($prefix);
            }
        }

        return $match;
    }
}
