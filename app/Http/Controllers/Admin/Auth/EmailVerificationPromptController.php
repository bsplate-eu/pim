<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Http\Controllers\Admin\Controller;
use App\Settings\GeneralSettings;
use Illuminate\Http\Request;
use Inertia\Inertia;

class EmailVerificationPromptController extends Controller
{
    /**
     * Display the email verification prompt.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return mixed
     */
    public function __invoke(Request $request)
    {
        return $request->user('crafter')->hasVerifiedEmail()
                    ? redirect()->intended(app(GeneralSettings::class)->default_route)
                    : Inertia::render('Auth/VerifyEmail', ['status' => session('status')]);
    }
}
