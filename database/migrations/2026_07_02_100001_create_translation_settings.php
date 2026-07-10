<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

class CreateTranslationSettings extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('translation.auto_translate_on_sync', false);
        $this->migrator->add('translation.auto_approve_enabled', false);
        $this->migrator->add('translation.auto_approve_min_coverage', 6);
    }
}
