<?php

namespace App\Models\Connect;

use Illuminate\Database\Eloquent\Model;

/**
 * Konfiguracja raportu „chatbot" (Argo Connect → Integracja chatboot).
 * Jeden wiersz na raport (report_key). Wysyłka przez CallMeBot WhatsApp
 * (App\Services\Ksef\SignalSender). phone/api_key puste = jak w konfiguracji KSeF.
 *
 * @see \App\Http\Controllers\Admin\Connect\ChatbotController
 * @see \App\Console\Commands\ConnectSalesReport
 */
class ChatbotReport extends Model
{
    protected $table = 'chatbot_reports';

    protected $fillable = [
        'report_key',
        'enabled',
        'template',
        'send_time',
        'phone',
        'api_key',
        'last_sent_date',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'last_sent_date' => 'date',
    ];

    public const KEY_SALES = 'sales';

    /** Domyślny szablon raportu sprzedaży (placeholdery rozwijane przez SalesReportService). */
    public const DEFAULT_SALES_TEMPLATE = "Sprzedaż {data}:\n{sprzedaz_per_kraj}\nRazem: {razem_dzis}\n\nObrót w tym tygodniu: {obrot_tydzien}\nObrót w tym miesiącu: {obrot_miesiac}";

    /** Wiersz konfiguracji raportu; jeśli brak — instancja z domyślnymi (niezapisana). */
    public static function forKey(string $key): self
    {
        return static::query()->firstWhere('report_key', $key) ?? new static([
            'report_key' => $key,
            'enabled' => false,
            'template' => $key === self::KEY_SALES ? self::DEFAULT_SALES_TEMPLATE : '',
            'send_time' => '20:00',
        ]);
    }
}
