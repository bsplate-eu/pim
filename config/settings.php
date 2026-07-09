<?php

use App\Settings\GeneralSettings;
use App\Settings\MailSettings;
use App\Settings\TranslationSettings;

return [
    'settings' => [
        GeneralSettings::class,
        MailSettings::class, // [argo-mail-pkg] transakcyjny SMTP
        TranslationSettings::class, // moduł tłumaczeń — ustawienia
    ],
];
