<?php

namespace App\Http\Controllers\Admin;

use App\Models\AdminUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MentionController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        $users = AdminUser::query()
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($w) use ($q) {
                    $w->where('first_name', 'like', "%{$q}%")
                      ->orWhere('last_name', 'like', "%{$q}%")
                      ->orWhere('email', 'like', "%{$q}%");
                });
            })
            ->orderBy('first_name')
            ->limit(10)
            ->get(['id', 'first_name', 'last_name', 'email', 'avatar_url']);

        return response()->json([
            'users' => $users->map(fn ($u) => [
                'id'     => $u->id,
                'label'  => trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')),
                'email'  => $u->email,
                'avatar' => $u->avatar_url,
            ]),
        ]);
    }
}
