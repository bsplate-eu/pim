<?php

namespace App\Http\Controllers\Admin\Mail;

use App\Http\Controllers\Admin\Controller;
use App\Models\Mail\MailUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MailUserController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'admin_user_id' => ['required', 'integer', 'exists:admin_users,id', Rule::unique('mail_users', 'admin_user_id')],
            'color'         => ['nullable', 'string', 'max:16'],
        ]);

        MailUser::create([
            'admin_user_id' => $data['admin_user_id'],
            'color'         => $data['color'] ?? '#2563eb',
            'sort'          => (int) MailUser::max('sort') + 1,
        ]);

        return back()->with('success', 'Dodano osobę do obsługi poczty.');
    }

    public function update(Request $request, MailUser $mailUser): RedirectResponse
    {
        $data = $request->validate([
            'color' => ['nullable', 'string', 'max:16'],
        ]);

        $mailUser->update(['color' => $data['color'] ?? $mailUser->color]);

        return back()->with('success', 'Zaktualizowano.');
    }

    public function destroy(MailUser $mailUser): RedirectResponse
    {
        $mailUser->delete();

        return back()->with('success', 'Usunięto osobę z obsługi poczty.');
    }
}
