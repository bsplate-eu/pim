<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Dodaje 'mail.account_id' — opcjonalny wskaznik na skrzynke z Argo Mail
 * (mail_accounts), z ktorej maja byc wysylane maile systemowe.
 * null = uzyj recznej konfiguracji SMTP (pola host/port/... ponizej).
 */
class AddAccountIdToMailSettings extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('mail.account_id', null);
    }
}
