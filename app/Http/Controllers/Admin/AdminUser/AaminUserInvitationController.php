<?php

namespace App\Http\Controllers\Admin\AdminUser;

use App\Http\Controllers\Admin\Controller;
use App\Http\Requests\Admin\Auth\InviteUserRequest;
use App\Http\Requests\Admin\Auth\InviteUserStoreRequest;
use App\Mail\InvitationUserMail;
use App\Models\AdminUser;
use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Inertia;

class AaminUserInvitationController extends Controller
{
    public function inviteUser(InviteUserRequest $request)
    {
        $data = $request->validated();

        $user = AdminUser::create([
            'email' => $data['email'],
            'password' => bcrypt(Str::random(12)),
            'locale' => app(GeneralSettings::class)->default_locale,
            'active' => false,
            'invitation_sent_at' => now(),
        ])->assignRole($data['role_id']);

        static::sendInvitation(
            email: $data['email'],
            userFullName: Auth::user()->first_name . " " . Auth::user()->last_name
        );

        return redirect()->back()->with(['message' => ___("crafter", "User was succesfully invited.")]);
    }

    public function createInviteAcceptationUser($email)
    {
        $user = AdminUser::whereEmail($email)->firstOrFail();

        if (! $user->wasInvited()) {
            return redirect()->route("crafter.login");
        }

        return Inertia::render('Auth/InviteUser', [
            'email' => $email,
        ]);
    }

    public function storeInviteAcceptationUser(InviteUserStoreRequest $request)
    {
        $data = $request->validated();
        $user = AdminUser::whereEmail($data['email'])->first();
        $data['password'] = bcrypt($data['password']);
        $user->update($data + ['active' => true, 'invitation_accepted_at' => now()]);
        $user->markEmailAsVerified();

        return redirect()->route('crafter.login');
    }

    public static function sendInvitation(string $email, string $userFullName)
    {
        Mail::to($email)->send(new InvitationUserMail([
            'email' => $email,
            'userFullName' => $userFullName,
        ]));
    }
}
