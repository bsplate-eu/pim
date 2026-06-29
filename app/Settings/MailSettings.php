<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * [argo-mail-pkg] Ustawienia transakcyjnego SMTP (moduł "Poczta": Mail SMTP / Szablony / Logi).
 *
 * Properties odpowiadają kluczom dodanym w migracji
 * database/migrations/2026_04_20_100000_create_mail_settings.php (grupa "mail").
 * Hasło trzymane zaszyfrowane (Crypt/APP_KEY); puste = używaj wartości z .env.
 */
class MailSettings extends Settings
{
    public bool $override_env;
    public string $host;
    public int $port;
    public string $username;
    public string $password;
    public string $encryption;
    public string $from_address;
    public string $from_name;

    public static function group(): string
    {
        return 'mail';
    }

    /** Zapisz hasło SMTP zaszyfrowane (puste = brak / użyj .env). */
    public function setPasswordFromPlaintext(string $plaintext): void
    {
        $this->password = $plaintext === '' ? '' : encrypt($plaintext);
    }

    /** Odszyfrowane hasło SMTP (puste, jeśli nie ustawiono lub błąd odszyfrowania). */
    public function passwordPlaintext(): string
    {
        if ($this->password === '') {
            return '';
        }

        try {
            return (string) decrypt($this->password);
        } catch (\Throwable $e) {
            return '';
        }
    }
}
