<?php

namespace App\Http\Controllers\Admin\AdminUser;

use App\Models\AdminUser;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

class MyPasswordController extends Controller
{
    private AdminUser $adminUser;

    private function setUser(Request $request)
    {
        $this->adminUser = $request->user('crafter');
    }

    public function edit(Request $request)
    {
        $this->setUser($request);

        return Inertia::render('AdminUser/Password/Edit', [
            'adminUser' => $this->adminUser,
        ]);
    }

    public function update(Request $request)
    {
        $this->setUser($request);

        $request->validate([
            'password' => ['required', 'confirmed', Password::default()],
        ]);

        $this->adminUser->update([
            'password' => Hash::make($request->get('password')),
        ]);

        return redirect()->back()->with(['message' => ___('crafter', 'Password successfully updated')]);
    }
}
