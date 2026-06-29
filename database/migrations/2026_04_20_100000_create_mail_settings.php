<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

class CreateMailSettings extends SettingsMigration
{
    public function up(): void
    {
        // Defaults copied from current .env so that initial DB state == current env state.
        $this->migrator->add('mail.override_env', false);
        $this->migrator->add('mail.host', (string) env('MAIL_HOST', ''));
        $this->migrator->add('mail.port', (int) env('MAIL_PORT', 587));
        $this->migrator->add('mail.username', (string) env('MAIL_USERNAME', ''));
        $this->migrator->add('mail.password', ''); // stored encrypted; empty = use .env
        $this->migrator->add('mail.encryption', (string) env('MAIL_ENCRYPTION', 'tls'));
        $this->migrator->add('mail.from_address', (string) env('MAIL_FROM_ADDRESS', ''));
        $this->migrator->add('mail.from_name', (string) env('MAIL_FROM_NAME', ''));
    }
}
