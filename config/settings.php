<?php

use App\Settings\GeneralSettings;
use App\Settings\MailSettings;

return [
    'settings' => [
        GeneralSettings::class,
        MailSettings::class, // [argo-mail-pkg] transakcyjny SMTP
    ],
];
