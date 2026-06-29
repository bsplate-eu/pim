<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Http\Controllers\Admin\Controller;
use App\Settings\GeneralSettings;
use Illuminate\Http\Request;

class EmailVerificationNotificationController extends Controller
{
    /**
     * Send a new email verification notification.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request)
    {
        if ($request->user('crafter')->hasVerifiedEmail()) {
            return redirect()->intended(app(GeneralSettings::class)->default_route);
        }

        $request->user('crafter')->sendEmailVerificationNotification();

        return back()->with('status', 'verification-link-sent');
    }
}
