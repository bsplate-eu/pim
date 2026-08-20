<?php

namespace App\Providers;

use App\Listeners\LogSentMail;
use App\Settings\MailSettings;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class MailConfigServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Override mail config from DB if enabled. Silently fail if settings table
        // not yet migrated (e.g. during initial `migrate`) so we don't break boot.
        try {
            $s = app(MailSettings::class);

            if ($s->override_env) {
                // Priorytet: wybrana skrzynka z Argo Mail (reuse jej SMTP), inaczej reczne pola.
                $account = $s->account_id
                    ? \App\Models\Mail\Account::find($s->account_id)
                    : null;

                if ($account) {
                    config([
                        'mail.default' => 'smtp',
                        'mail.mailers.smtp.host' => $account->smtp_host,
                        'mail.mailers.smtp.port' => $account->smtp_port,
                        'mail.mailers.smtp.username' => $account->username ?: $account->email,
                        'mail.mailers.smtp.password' => $account->password, // cast 'encrypted' => odszyfrowane
                        'mail.mailers.smtp.encryption' => $account->smtp_encryption ?: null,
                        'mail.from.address' => $account->email,
                        'mail.from.name' => $s->from_name !== '' ? $s->from_name : $account->label,
                    ]);
                } else {
                    config([
                        'mail.default' => 'smtp',
                        'mail.mailers.smtp.host' => $s->host,
                        'mail.mailers.smtp.port' => $s->port,
                        'mail.mailers.smtp.username' => $s->username,
                        'mail.mailers.smtp.password' => $s->passwordPlaintext(),
                        'mail.mailers.smtp.encryption' => $s->encryption !== '' ? $s->encryption : null,
                        'mail.from.address' => $s->from_address,
                        'mail.from.name' => $s->from_name,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            // settings not available (fresh install, migration not run) — fall back to .env
        }

        // Log every successfully sent mail. Failures are logged by the caller
        // (controller / sender service) via MailLog::create([..., 'status' => 'failed']).
        Event::listen(MessageSent::class, LogSentMail::class);
    }
}
