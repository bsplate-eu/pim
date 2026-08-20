<?php

namespace App\Settings;

use Illuminate\Support\Facades\Crypt;
use Spatie\LaravelSettings\Settings;

/**
 * SMTP settings stored in DB (overrides .env at runtime via MailConfigServiceProvider).
 *
 * Password is stored encrypted via Crypt::encryptString. Use passwordPlaintext() to decrypt
 * when applying to Laravel mail config. Never expose raw password in JSON / API / Inertia props.
 */
class MailSettings extends Settings
{
    /** When false, .env values are used and DB settings are ignored. */
    public bool $override_env;

    /**
     * ID skrzynki z Argo Mail (mail_accounts) uzytej do wysylki maili systemowych.
     * null = uzyj recznych pol SMTP ponizej.
     */
    public ?int $account_id;

    public string $host;
    public int $port;
    public string $username;

    /** Encrypted (Crypt::encryptString). Empty string means "no password set / use .env". */
    public string $password;

    /** "tls" | "ssl" | "" (empty = no encryption) */
    public string $encryption;

    public string $from_address;
    public string $from_name;

    public static function group(): string
    {
        return 'mail';
    }

    public function passwordPlaintext(): string
    {
        if ($this->password === '') {
            return '';
        }
        try {
            return Crypt::decryptString($this->password);
        } catch (\Throwable $e) {
            return '';
        }
    }

    public function setPasswordFromPlaintext(string $plain): void
    {
        $this->password = $plain === '' ? '' : Crypt::encryptString($plain);
    }
}
