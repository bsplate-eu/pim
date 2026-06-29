<?php

namespace App\Http\Middleware;

use App\Models\ArgoProjectGroup;
use App\Settings\GeneralSettings;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     * @var string
     */
    protected $rootView = 'crafter';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     * @param  \Illuminate\Http\Request  $request
     * @return string|null
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Defines the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function share(Request $request): array
    {
        $settings = app(GeneralSettings::class);

        return array_merge(parent::share($request), [
            'auth' => [
                'user' => fn () => $request->user() ? $request->user()->only('id', 'first_name', 'last_name', 'email', 'initials', 'avatar_url', 'locale') : null,
                'permissions' => fn () => $request->user() ? $request->user()->getAllPermissions()->pluck('name') : [],
                'unreadNotifications' => function () use ($request) {
                    try {
                        return $request->user()
                            ? (int) $request->user()->unreadNotifications()->count()
                            : 0;
                    } catch (\Throwable $e) {
                        return 0;
                    }
                },
                // [argo-mail-pkg] Web Push — klucz publiczny VAPID dla front-endu (opt-in push)
                'vapidPublicKey' => config('webpush.vapid.public_key'),
            ],
            'message' => fn () => $request->session()->get('message'),
            'sort' => fn () => $request->get('sort'),
            'filter' => fn () => $request->get('filter'),
            'csrf_token' => csrf_token(),
            'config' => [
                'crafter' => [
                    'track_user_last_active_time' => config('crafter.track_user_last_active_time', false),
                ],
            ],
            'settings' => [
                'available_locales' => $settings->available_locales,
            ],
            'argoProjectGroups' => function () use ($request) {
                try {
                    return $request->user()
                        ? ArgoProjectGroup::query()
                            ->with(['projects' => fn ($q) => $q->select('id', 'argo_project_group_id', 'name', 'icon', 'color')])
                            ->orderBy('position')
                            ->orderBy('id')
                            ->get(['id', 'name', 'icon', 'color'])
                        : [];
                } catch (\Throwable $e) {
                    return [];
                }
            },
        ]);
    }
}
