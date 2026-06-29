<?php

namespace App\Http\Controllers\Admin\AdminUser;

use App\Models\AdminUser;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;

class MyProfileController extends Controller
{
    private AdminUser $adminUser;

    private function setUser(Request $request)
    {
        $this->adminUser = $request->user('crafter');
    }

    public function edit(Request $request)
    {
        $this->setUser($request);

        return Inertia::render('AdminUser/Profile/Edit', [
            'adminUser' => $this->adminUser,
            'locales' => getAvailableLocalesTranslated(),
        ]);
    }

    public function update(Request $request)
    {
        $this->setUser($request);

        $validated = $request->validate([
            'first_name' => ['nullable', 'string'],
            'last_name' => ['nullable', 'string'],
            'locale' => ['sometimes', 'string'],
        ]);

        $this->adminUser->update($validated);

        return redirect()->back()->with(['message' => ___('crafter', 'Profile successfully updated')]);
    }
}
