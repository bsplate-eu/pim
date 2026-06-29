<?php

namespace App\Http\Controllers\Admin\Connect;

use App\Http\Controllers\Admin\Controller;
use App\Models\Connect\ChatbotReport;
use App\Models\Ksef\KsefSignalSettings;
use App\Services\Connect\SalesReportService;
use App\Services\Ksef\SignalSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Argo Connect → Integracja chatboot.
 * Raporty wysyłane na WhatsApp (CallMeBot). Na razie: Raport sprzedaży.
 *
 * @see \App\Services\Connect\SalesReportService
 */
class ChatbotController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Connect/Chatbot/Index', [
            'sales' => $this->salesPayload(),
            'ksef' => $this->ksefPayload(),
        ]);
    }

    /** Ustawienia powiadomień KSeF (przeniesione tu z karty KSeF; zapis/test przez trasy ksef.signal.*). */
    private function ksefPayload(): array
    {
        $s = KsefSignalSettings::current();

        return [
            'enabled' => (bool) $s->enabled,
            'phone' => $s->phone,
            'api_key' => $s->api_key,
            'template' => $s->template ?: KsefSignalSettings::DEFAULT_TEMPLATE,
            'send_time' => $s->send_time ?: '07:00',
            'last_sent_date' => $s->last_sent_date?->toDateString(),
        ];
    }

    private function salesPayload(): array
    {
        $r = ChatbotReport::forKey(ChatbotReport::KEY_SALES);

        return [
            'enabled' => (bool) $r->enabled,
            'template' => $r->template ?: ChatbotReport::DEFAULT_SALES_TEMPLATE,
            'send_time' => $r->send_time ?: '20:00',
            'phone' => $r->phone,
            'api_key' => $r->api_key,
            'last_sent_date' => $r->last_sent_date?->toDateString(),
        ];
    }

    /** Zapis konfiguracji raportu sprzedaży. */
    public function updateSales(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'template' => ['nullable', 'string', 'max:3000'],
            'send_time' => ['required', 'string', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'phone' => ['nullable', 'string', 'max:32'],
            'api_key' => ['nullable', 'string', 'max:128'],
        ]);

        $report = ChatbotReport::query()->firstWhere('report_key', ChatbotReport::KEY_SALES)
            ?? new ChatbotReport(['report_key' => ChatbotReport::KEY_SALES]);

        $report->enabled = (bool) $data['enabled'];
        $report->template = $data['template'] ?: ChatbotReport::DEFAULT_SALES_TEMPLATE;
        $report->send_time = $data['send_time'];
        $report->phone = $data['phone'] ?? null;
        $report->api_key = $data['api_key'] ?? null;
        $report->save();

        return back()->with('success', 'Raport sprzedaży zapisany.');
    }

    /** Test wysyłki — wartości z formularza (bez zapisu); zwraca treść i wynik. */
    public function testSales(Request $request, SalesReportService $sales, SignalSender $sender): JsonResponse
    {
        $data = $request->validate([
            'template' => ['nullable', 'string', 'max:3000'],
            'phone' => ['nullable', 'string', 'max:32'],
            'api_key' => ['nullable', 'string', 'max:128'],
        ]);

        $global = KsefSignalSettings::current();
        $phone = ($data['phone'] ?? null) ?: $global->phone;
        $apiKey = ($data['api_key'] ?? null) ?: $global->api_key;

        $message = $sales->renderTemplate($data['template'] ?: ChatbotReport::DEFAULT_SALES_TEMPLATE);
        $result = $sender->sendTo($message, $phone, $apiKey);

        return response()->json([
            'ok' => $result['ok'],
            'error' => $result['error'],
            'message' => $message,
        ]);
    }
}
