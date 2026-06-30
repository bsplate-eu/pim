<?php

namespace App\Models\Ksef;

use Illuminate\Database\Eloquent\Model;

/**
 * Globalna konfiguracja powiadomień Signal (KSeF → do zapłaty).
 * Singleton: zawsze pracujemy na jednym wierszu (current()).
 *
 * @see \App\Http\Controllers\Admin\KsefController updateSignalSettings/sendSignalTest
 * @see \App\Console\Commands\KsefSignalDue
 */
class KsefSignalSettings extends Model
{
    protected $table = 'ksef_signal_settings';

    protected $fillable = [
        'enabled',
        'phone',
        'api_key',
        'template',
        'send_time',
        'last_sent_date',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'last_sent_date' => 'date',
    ];

    /** Domyślny szablon — obie firmy, sama kwota „na dziś". */
    public const DEFAULT_TEMPLATE = "Cześć Maks. Dziś do zapłaty:\nPARETO: {pareto}\nBSP: {bsp}\n\nLista opóźnionych płatności:\n{przeterminowane}\nRazem: {przeterminowane_razem}";

    /** Jedyny wiersz konfiguracji; jeśli brak — instancja z domyślnymi (niezapisana). */
    public static function current(): self
    {
        return static::query()->first() ?? new static([
            'enabled' => false,
            'template' => self::DEFAULT_TEMPLATE,
            'send_time' => '07:00',
        ]);
    }
}
