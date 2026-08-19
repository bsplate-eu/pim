<?php

namespace App\Support;

use Spatie\Permission\Models\Permission;

/**
 * Ludzkie nazwy uprawnień dla ekranów „Role" i „Uprawnienia".
 *
 * Do tej pory macierz uprawnień pokazywała surowe klucze
 * (`crafter.integration-product.destroy`), bo plik
 * resources/translations/permissions/permission_translations.json mapuje klucz
 * na samego siebie. Zamiast utrzymywać 80 wpisów ręcznie, składamy etykietę
 * z nazwy zasobu i akcji, a wyjątki trzymamy w OVERRIDES.
 *
 * Zwracana mapa zawiera też węzły pośrednie drzewa (np. `crafter.product`),
 * bo front renderuje je jako nagłówki grup.
 */
class PermissionLabels
{
    /** Zasób → nazwa w panelu. */
    private const RESOURCES = [
        'admin-user'          => 'Użytkownicy',
        'role'                => 'Role',
        'permission'          => 'Uprawnienia',
        'translation'         => 'Lokalizacja (teksty interfejsu)',
        'media'               => 'Media',
        'tag'                 => 'Tagi',
        'settings'            => 'Ustawienia systemu',
        'product'             => 'Produkty',
        'category'            => 'Kategorie',
        'pricelist'           => 'Cenniki',
        'source'              => 'Źródła',
        'template'            => 'Szablony',
        'attribute'           => 'Atrybuty',
        'attribute-value'     => 'Wartości atrybutów',
        'integration'         => 'Integracje',
        'integration-product' => 'Produkty w integracji',
        'ai-tool'             => 'Narzędzia AI',
        'mail'                => 'Poczta (SMTP)',
        'module'              => 'Moduły Argo',
    ];

    /** Akcja → czasownik w panelu. */
    private const ACTIONS = [
        'index'            => 'Podgląd listy',
        'show'             => 'Podgląd szczegółów',
        'view'             => 'Podgląd',
        'create'           => 'Dodawanie',
        'store'            => 'Dodawanie',
        'edit'             => 'Edycja',
        'destroy'          => 'Usuwanie',
        'upload'           => 'Wgrywanie plików',
        'export'           => 'Eksport',
        'import'           => 'Import',
        'publish'          => 'Publikacja',
        'rescan'           => 'Skanowanie tekstów',
        'impersonal-login' => 'Logowanie jako inny użytkownik',
    ];

    /** Pełne klucze, których nie da się złożyć automatycznie. */
    private const OVERRIDES = [
        'crafter'                     => 'Dostęp do panelu',

        'crafter.mail.view'           => 'Podgląd konfiguracji SMTP',
        'crafter.mail.edit'           => 'Edycja konfiguracji SMTP',
        'crafter.mail.logs.view'      => 'Podgląd logów poczty',
        'crafter.mail.logs'           => 'Logi poczty',
        'crafter.mail.templates.edit' => 'Edycja szablonów maili',
        'crafter.mail.templates'      => 'Szablony maili',

        'crafter.module.costs'        => 'Argo HQ · Koszty (planer, zestawienia, raporty, wyciągi)',
        'crafter.module.kasa'         => 'Argo HQ · Kasa',
        'crafter.module.ksef'         => 'Argo HQ · KSeF (faktury Pareto / BSP)',
        'crafter.module.connect'      => 'Argo Connect (zamówienia, klienci, mapa, integracje)',
        'crafter.module.marketplace'  => 'Argo Connect · Marketplace eBay (oferty, kategorie, wystawianie)',
        'crafter.module.scope'        => 'Argo Scope (scrapy konkurencji)',
        'crafter.module.task'         => 'Argo Task (projekty i zadania)',
        'crafter.module.mail'         => 'Argo Mail (skrzynka, konta, administrator AI)',
        'crafter.module.translations' => 'Tłumaczenia (matryca, review, logi, ustawienia)',

        'crafter.mail-account'        => 'Argo Mail · skrzynki',
        'crafter.mail-account.all'    => 'Dostęp do WSZYSTKICH skrzynek (bez tego — tylko przypisane imiennie)',
    ];

    /**
     * Mapa: pełny klucz uprawnienia LUB węzeł pośredni => etykieta.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        $labels = [];

        foreach (Permission::query()->pluck('name') as $name) {
            $segments = explode('.', $name);

            // Węzły pośrednie: crafter, crafter.product, crafter.module, …
            for ($i = 1; $i <= count($segments); $i++) {
                $key = implode('.', array_slice($segments, 0, $i));

                if (! isset($labels[$key])) {
                    $labels[$key] = self::labelFor($key, $segments, $i);
                }
            }
        }

        return $labels;
    }

    /**
     * @param  array<int, string>  $segments  rozbity pełny klucz uprawnienia
     * @param  int  $depth  ile segmentów obejmuje etykietowany klucz
     */
    private static function labelFor(string $key, array $segments, int $depth): string
    {
        if (isset(self::OVERRIDES[$key])) {
            return self::OVERRIDES[$key];
        }

        // crafter.<zasób> → nazwa zasobu
        if ($depth === 2) {
            return self::RESOURCES[$segments[1]] ?? self::humanize($segments[1]);
        }

        // crafter.<zasób>.<akcja> → czasownik (nazwa zasobu jest w nagłówku grupy)
        if ($depth === 3) {
            return self::ACTIONS[$segments[2]] ?? self::humanize($segments[2]);
        }

        return self::humanize($segments[$depth - 1]);
    }

    private static function humanize(string $segment): string
    {
        return ucfirst(str_replace('-', ' ', $segment));
    }
}
