<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Ustawienia modułu tłumaczeń (Tłumaczenia → Ustawienia).
 */
class TranslationSettings extends Settings
{
    /** Automatycznie tłumacz nowe produkty z matrycy (po nocnym syncu / imporcie). */
    public bool $auto_translate_on_sync;

    /** Automatycznie zatwierdzaj produkty, które osiągnęły próg pokrycia. */
    public bool $auto_approve_enabled;

    /** Ile locale musi być pokrytych, by auto-approve wpuścił produkt do eksportu (6 = komplet). */
    public int $auto_approve_min_coverage;

    public static function group(): string
    {
        return 'translation';
    }
}
