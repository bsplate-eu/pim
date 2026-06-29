<?php

namespace App\Http\Controllers\Admin\Mail;

use App\Http\Controllers\Admin\Controller;
use App\Models\Mail\Account;
use App\Models\Mail\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Webklex\IMAP\Facades\Client;

class AccountController extends Controller
{
    public function index(): Response
    {
        $accounts = Account::query()->orderBy('label')->get();

        $stats = Message::query()
            ->selectRaw('account_id, COUNT(*) as total, SUM(is_read = 0) as unread')
            ->groupBy('account_id')
            ->get()
            ->keyBy('account_id');

        return Inertia::render('ArgoMail/Accounts/Index', [
            'accounts' => $accounts->map(fn (Account $a) => [
                'id'             => $a->id,
                'label'          => $a->label,
                'email'          => $a->email,
                'color'          => $a->color,
                'is_active'      => $a->is_active,
                'sync_status'    => $a->sync_status,
                'sync_error'     => $a->sync_error,
                'last_sync_at'   => $a->last_sync_at?->toIso8601String(),
                'messages_count' => (int) ($stats[$a->id]->total ?? 0),
                'unread_count'   => (int) ($stats[$a->id]->unread ?? 0),
            ])->values(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('ArgoMail/Accounts/Form', [
            'account' => null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateAccount($request);

        $account = new Account();
        $this->applyData($account, $data);
        $account->password = $this->normalizeAppPassword($data['password']); // wymagane przy tworzeniu (walidacja)
        $account->save();

        return redirect()
            ->route('crafter.argo-mail.accounts.index')
            ->with('success', "Skrzynka „{$account->label}” dodana.");
    }

    public function edit(Account $account): Response
    {
        return Inertia::render('ArgoMail/Accounts/Form', [
            // UWAGA: świadomie NIE wysyłamy hasła do front-endu — tylko flagę.
            'account' => [
                'id'                 => $account->id,
                'label'              => $account->label,
                'email'              => $account->email,
                'color'              => $account->color,
                'imap_host'          => $account->imap_host,
                'imap_port'          => $account->imap_port,
                'imap_encryption'    => $account->imap_encryption,
                'smtp_host'          => $account->smtp_host,
                'smtp_port'          => $account->smtp_port,
                'smtp_encryption'    => $account->smtp_encryption,
                'username'           => $account->username,
                'has_password'       => filled($account->password),
                'sync_window_months' => $account->sync_window_months,
                'signature'          => $account->signature,
                'is_active'          => $account->is_active,
            ],
        ]);
    }

    public function update(Request $request, Account $account): RedirectResponse
    {
        $data = $this->validateAccount($request, $account->id);

        $this->applyData($account, $data);
        if (! empty($data['password'])) {
            $account->password = $this->normalizeAppPassword($data['password']);
        }
        $account->save();

        return redirect()
            ->route('crafter.argo-mail.accounts.index')
            ->with('success', 'Skrzynka zaktualizowana.');
    }

    public function destroy(Account $account): RedirectResponse
    {
        $label = $account->label;
        $account->delete();

        return redirect()
            ->route('crafter.argo-mail.accounts.index')
            ->with('success', "Skrzynka „{$label}” usunięta.");
    }

    /**
     * Test połączenia IMAP (odbiór) + SMTP (wysyłka) bez zapisywania i bez wysyłania maila.
     */
    public function test(Request $request, ?Account $account = null): JsonResponse
    {
        $data = $request->validate([
            'imap_host'       => ['required', 'string'],
            'imap_port'       => ['required', 'integer', 'between:1,65535'],
            'imap_encryption' => ['nullable', Rule::in(['ssl', 'tls', 'starttls'])],
            'smtp_host'       => ['required', 'string'],
            'smtp_port'       => ['required', 'integer', 'between:1,65535'],
            'smtp_encryption' => ['nullable', Rule::in(['ssl', 'tls', 'starttls'])],
            'username'        => ['nullable', 'string'],
            'email'           => ['nullable', 'email'],
            'password'        => ['nullable', 'string'],
        ]);

        $username = $data['username'] ?: ($data['email'] ?? $account?->username ?? $account?->email);
        // Przy edycji z pustym polem hasła — użyj zapisanego (odszyfrowanego) hasła.
        $password = $this->normalizeAppPassword($data['password'] ?? '') ?: $account?->password;

        if (empty($password)) {
            return response()->json([
                'ok'      => false,
                'message' => 'Brak hasła do przetestowania.',
            ], 422);
        }

        // Nie pozwól wisieć w nieskończoność na złym hoście.
        $previousTimeout = ini_get('default_socket_timeout');
        ini_set('default_socket_timeout', '20');

        $imap = $this->testImap($data, $username, $password);
        $smtp = $this->testSmtp($data, $username, $password);

        ini_set('default_socket_timeout', (string) $previousTimeout);

        $ok = $imap['ok'] && $smtp['ok'];

        return response()->json([
            'ok'      => $ok,
            'message' => $ok
                ? 'Połączono — IMAP i SMTP działają.'
                : 'Połączenie niepełne — sprawdź szczegóły poniżej.',
            'imap'    => $imap,
            'smtp'    => $smtp,
        ]);
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array{ok: bool, message: string}
     */
    private function testImap(array $data, ?string $username, string $password): array
    {
        try {
            $client = Client::make([
                'host'          => $data['imap_host'],
                'port'          => (int) $data['imap_port'],
                'encryption'    => $data['imap_encryption'] ?: false,
                'validate_cert' => true,
                'username'      => $username,
                'password'      => $password,
                'protocol'      => 'imap',
            ]);

            $client->connect(); // rzuca wyjątek przy błędnym haśle / połączeniu

            $message = 'OK — połączono i zalogowano.';
            try {
                $message = 'OK — znaleziono '.$client->getFolders(false)->count().' folderów.';
            } catch (\Throwable) {
                // listowanie folderów nieistotne dla samego testu logowania
            }

            $client->disconnect();

            return ['ok' => true, 'message' => $message];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $this->cleanMessage($e->getMessage())];
        }
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array{ok: bool, message: string}
     */
    private function testSmtp(array $data, ?string $username, string $password): array
    {
        try {
            // 465 = implicit SSL (tls=true); 587/starttls = tls=false → auto-STARTTLS podczas EHLO.
            $tls = ($data['smtp_encryption'] ?? null) === 'ssl';

            $transport = new EsmtpTransport($data['smtp_host'], (int) $data['smtp_port'], $tls);
            $transport->setUsername((string) $username);
            $transport->setPassword($password);
            $transport->start(); // łączy + EHLO + (STARTTLS) + AUTH; rzuca wyjątek przy błędzie
            $transport->stop();

            return ['ok' => true, 'message' => 'OK — uwierzytelniono.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $this->cleanMessage($e->getMessage())];
        }
    }

    private function cleanMessage(string $msg): string
    {
        $msg = trim(preg_replace('/\s+/', ' ', $msg) ?? $msg);

        return mb_strlen($msg) > 300 ? mb_substr($msg, 0, 300).'…' : $msg;
    }

    /**
     * Hasło aplikacji Google to 16 liter, pokazywane w 4 grupach po 4 (ze spacjami).
     * Gmail oczekuje wersji bez spacji — usuwamy je, gdy wynik to dokładnie 16 liter.
     * Inne hasła (mogące zawierać spacje) zostawiamy bez zmian.
     */
    private function normalizeAppPassword(?string $password): string
    {
        $password = (string) $password;
        $stripped = preg_replace('/\s+/', '', $password) ?? $password;

        if (preg_match('/^[A-Za-z]{16}$/', $stripped)) {
            return $stripped;
        }

        return trim($password);
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function applyData(Account $account, array $data): void
    {
        $account->label              = $data['label'];
        $account->email              = $data['email'];
        $account->color              = $data['color'] ?? null;
        $account->imap_host          = $data['imap_host'];
        $account->imap_port          = (int) $data['imap_port'];
        $account->imap_encryption    = $data['imap_encryption'] ?: null;
        $account->smtp_host          = $data['smtp_host'];
        $account->smtp_port          = (int) $data['smtp_port'];
        $account->smtp_encryption    = $data['smtp_encryption'] ?: null;
        $account->username           = $data['username'] ?: $data['email'];
        $account->auth_type          = Account::AUTH_PASSWORD;
        $account->sync_window_months = (int) ($data['sync_window_months'] ?? 6);
        $account->signature          = $data['signature'] ?? null;
        $account->is_active          = (bool) ($data['is_active'] ?? true);
    }

    /**
     * @return array<string,mixed>
     */
    private function validateAccount(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'label'              => ['required', 'string', 'max:120'],
            'email'              => ['required', 'email', 'max:190', Rule::unique('mail_accounts', 'email')->ignore($ignoreId)],
            'color'              => ['nullable', 'string', 'max:16'],
            'imap_host'          => ['required', 'string', 'max:190'],
            'imap_port'          => ['required', 'integer', 'between:1,65535'],
            'imap_encryption'    => ['nullable', Rule::in(['ssl', 'tls', 'starttls'])],
            'smtp_host'          => ['required', 'string', 'max:190'],
            'smtp_port'          => ['required', 'integer', 'between:1,65535'],
            'smtp_encryption'    => ['nullable', Rule::in(['ssl', 'tls', 'starttls'])],
            'username'           => ['nullable', 'string', 'max:190'],
            'password'           => [$ignoreId ? 'nullable' : 'required', 'string', 'max:255'],
            'sync_window_months' => ['required', 'integer', 'between:1,24'],
            'signature'          => ['nullable', 'string', 'max:5000'],
            'is_active'          => ['nullable', 'boolean'],
        ]);
    }
}
