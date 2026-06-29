<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Http\Controllers\Admin\Controller;
use App\Settings\GeneralSettings;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Auth\EmailVerificationRequest;

class VerifyEmailController extends Controller
{
    /**
     * Mark the authenticated user's email address as verified.
     *
     * @param  \Illuminate\Foundation\Auth\EmailVerificationRequest  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function __invoke(EmailVerificationRequest $request)
    {
        if ($request->user('crafter')->hasVerifiedEmail()) {
            return redirect()->intended(app(GeneralSettings::class)->default_route.'?verified=1');
        }

        if ($request->user('crafter')->markEmailAsVerified()) {
            event(new Verified($request->user()));
        }

        return redirect()->intended(app(GeneralSettings::class)->default_route.'?verified=1');
    }
}
