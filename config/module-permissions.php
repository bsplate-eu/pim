<?php

/*
|--------------------------------------------------------------------------
| Uprawnienia modułowe (Argo)
|--------------------------------------------------------------------------
|
| Moduły dobudowane do Craftera (Argo HQ / Connect / Scope / Task / Mail /
| Tłumaczenia) nie mają własnych klas Request z bramkami Gate. Zamiast
| dopisywać je do ~50 kontrolerów, pilnuje ich jedno miejsce: middleware
| App\Http\Middleware\EnsureModuleAccess, który mapuje NAZWĘ TRASY na
| uprawnienie z tej tabeli.
|
| Klucz  = prefiks nazwy trasy BEZ przedrostka "crafter."
| Wartość= nazwa uprawnienia (guard "crafter")
|
| Wygrywa NAJDŁUŻSZY pasujący prefiks — dlatego "connect.marketplace."
| przebija "connect.". Trasa bez dopasowania jest przepuszczana (moduły
| Craftera mają własne bramki w klasach Request).
|
| Dodając nowy moduł: dopisz prefiks tutaj + uprawnienie w migracji
| 2026_08_19_100000_add_module_permissions.php (albo nowej) i pozycję menu
| oznacz w Sidebar.vue dyrektywą v-can.
|
*/

return [

    'map' => [
        // ——— Argo HQ ———
        'cost-planner.'              => 'crafter.module.costs',
        'bank-statements.'           => 'crafter.module.costs',
        'odyssey-cost.'              => 'crafter.module.costs',
        'kasa.'                      => 'crafter.module.kasa',
        'ksef.'                      => 'crafter.module.ksef',

        // ——— Argo Connect ———
        // Kolejność bez znaczenia: dopasowanie idzie po długości prefiksu.
        'connect.'                   => 'crafter.module.connect',
        'connect.integrations.ksef.' => 'crafter.module.ksef',
        'connect.integrations.ebay.' => 'crafter.module.marketplace',
        'connect.marketplace.'       => 'crafter.module.marketplace',

        // ——— Argo Scope ———
        'scope.'                     => 'crafter.module.scope',

        // ——— Argo Task ———
        'argo-task.'                 => 'crafter.module.task',
        'mobile.tasks'               => 'crafter.module.task',

        // ——— Argo Mail ———
        'argo-mail.'                 => 'crafter.module.mail',
        'ai-tools.mail.'             => 'crafter.module.mail',
        'mobile.mail'                => 'crafter.module.mail',

        // ——— Argo PIM ———
        'production.'                => 'crafter.module.production',

        // ——— Matryca tłumaczeń (v2) ———
        'translation-phrases.'       => 'crafter.module.translations',
        'translation-review.'        => 'crafter.module.translations',
        'translation-logs.'          => 'crafter.module.translations',
        'translation-settings.'      => 'crafter.module.translations',
    ],

    /*
    | Trasy wyłączone spod kontroli — techniczne endpointy używane przez UI
    | i rzeczy „własne" użytkownika (subskrypcja push, kursy NBP, PWA).
    */
    'except' => [
        'push.',
        'exchange-rates.',
        'mobile.home',
        'mobile.notifications',
    ],

];
