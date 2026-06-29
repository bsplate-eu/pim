<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class TrackLastActive
{
    public function handle(Request $request, Closure $next)
    {
        if (! config('crafter.track_user_last_active_time') || ! $request->user()) {
            return $next($request);
        }

        if (! $request->user()->last_active_at || $request->user()->last_active_at->isPast()) {
            $request->user()->last_active_at = now();

            $request->user()->saveQuietly();
        }

        return $next($request);
    }
}
