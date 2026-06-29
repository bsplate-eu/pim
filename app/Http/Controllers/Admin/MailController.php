<?php

namespace App\Http\Controllers\Admin;

use App\Models\MailLog;
use App\Models\MailTemplate;
use App\Settings\MailSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class MailController extends Controller
{
    // ---- SMTP ----

    public function smtp(MailSettings $settings): Response
    {
        $this->authorize('crafter.mail.view');

        return Inertia::render('Mail/Smtp', [
            'settings' => [
                'override_env' => $settings->override_env,
                'host' => $settings->host,
                'port' => $settings->port,
                'username' => $settings->username,
                'has_password' => $settings->password !== '',
                'encryption' => $settings->encryption,
                'from_address' => $settings->from_address,
                'from_name' => $settings->from_name,
            ],
            'env_fallback' => [
                'host' => (string) env('MAIL_HOST'),
                'port' => (int) env('MAIL_PORT', 587),
                'username' => (string) env('MAIL_USERNAME'),
                'encryption' => (string) env('MAIL_ENCRYPTION', 'tls'),
                'from_address' => (string) env('MAIL_FROM_ADDRESS'),
                'from_name' => (string) env('MAIL_FROM_NAME'),
            ],
        ]);
    }

    public function smtpUpdate(Request $request, MailSettings $settings): RedirectResponse
    {
        $this->authorize('crafter.mail.edit');

        $data = $request->validate([
            'override_env' => ['required', 'boolean'],
            'host' => ['required_if:override_env,true', 'nullable', 'string', 'max:255'],
            'port' => ['required_if:override_env,true', 'nullable', 'integer', 'between:1,65535'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:1024'],
            'encryption' => ['nullable', Rule::in(['tls', 'ssl', ''])],
            'from_address' => ['required_if:override_env,true', 'nullable', 'email', 'max:255'],
            'from_name' => ['required_if:override_env,true', 'nullable', 'string', 'max:255'],
        ]);

        $settings->override_env = (bool) $data['override_env'];
        $settings->host = (string) ($data['host'] ?? '');
        $settings->port = (int) ($data['port'] ?? 587);
        $settings->username = (string) ($data['username'] ?? '');

        // Only update password if user entered something (empty field = keep current)
        if (array_key_exists('password', $data) && $data['password'] !== null && $data['password'] !== '') {
            $settings->setPasswordFromPlaintext($data['password']);
        }

        $settings->encryption = (string) ($data['encryption'] ?? '');
        $settings->from_address = (string) ($data['from_address'] ?? '');
        $settings->from_name = (string) ($data['from_name'] ?? '');
        $settings->save();

        return redirect()->back()->with(['message' => 'Konfiguracja SMTP zapisana.']);
    }

    public function smtpTest(Request $request): RedirectResponse
    {
        $this->authorize('crafter.mail.edit');

        $data = $request->validate([
            'to' => ['required', 'email'],
        ]);

        try {
            Mail::raw(
                'To jest testowy mail wysłany z panelu PIM (' . now()->format('Y-m-d H:i:s') . ').',
                function ($m) use ($data) {
                    $m->to($data['to'])->subject('PIM — test SMTP');
                }
            );

            return redirect()->back()->with(['message' => 'Testowy mail wysłany do ' . $data['to']]);
        } catch (\Throwable $e) {
            MailLog::create([
                'to_email' => $data['to'],
                'subject' => 'PIM — test SMTP',
                'status' => MailLog::STATUS_FAILED,
                'error' => mb_substr($e->getMessage(), 0, 5000),
                'sent_at' => now(),
            ]);

            return redirect()->back()->with(['error' => 'Błąd wysyłki: ' . $e->getMessage()]);
        }
    }

    // ---- Templates ----

    public function templates(): Response
    {
        $this->authorize('crafter.mail.view');

        return Inertia::render('Mail/Templates', [
            'templates' => MailTemplate::query()
                ->orderBy('name')
                ->get(['id', 'key', 'name', 'subject', 'lang', 'is_active', 'updated_at']),
        ]);
    }

    public function templateEdit(MailTemplate $template): Response
    {
        $this->authorize('crafter.mail.view');

        return Inertia::render('Mail/TemplateEdit', [
            'template' => $template->only([
                'id', 'key', 'name', 'subject', 'body_html', 'variables', 'lang', 'is_active',
            ]),
        ]);
    }

    public function templateUpdate(Request $request, MailTemplate $template): RedirectResponse
    {
        $this->authorize('crafter.mail.templates.edit');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'body_html' => ['required', 'string'],
            'is_active' => ['required', 'boolean'],
        ]);

        $template->update($data);

        return redirect()->back()->with(['message' => 'Szablon zapisany.']);
    }

    // ---- Logs ----

    public function logs(Request $request): Response
    {
        $this->authorize('crafter.mail.logs.view');

        $logs = MailLog::query()
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Mail/Logs', [
            'logs' => $logs,
        ]);
    }
}
