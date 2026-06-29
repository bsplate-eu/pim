<?php

namespace App\Console\Commands;

use App\Models\AdminUser;
use App\Settings\GeneralSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class SeedCrafterUserCommand extends Command
{
    public $signature = 'crafter:create-admin-user';

    public $description = 'Create administrator access';

    public function handle(): int
    {
        // TODO consider alerting in production

        $default = 'admin@admin.com';
        $email = $this->option('no-interaction') ? $default : $this->components->ask('Creating an administrator account. Enter an email address (login): ', $default);

        $password = 'qwe123';

        $user = AdminUser::updateOrCreate([
            'email' => $email,
        ], [
            'first_name' => 'Admin',
            'last_name' => 'Admin',
            'email' => $email,
            'password' => bcrypt($password),
            'locale' => app(GeneralSettings::class)->default_locale,
        ]);

        $user->markEmailAsVerified();
        $user->assignRole(1);

        $this->components->info("Administrator account was created with credentials (login/password): <fg=green;options=bold>$email</> / <fg=blue;options=bold>$password</> - we recommend to change the password.");

        return self::SUCCESS;
    }
}
