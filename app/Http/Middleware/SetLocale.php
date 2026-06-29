<?php

namespace App\Http\Middleware;

use App\Settings\GeneralSettings;
use Closure;

class SetLocale
{
    public function handle($request, Closure $next)
    {
        if ($request->user()) {
            app()->setLocale($request->user()->locale);
        } else {
            app()->setLocale(app(GeneralSettings::class)->default_locale);
        }

        return $next($request);
    }
}
