<?php

namespace App\Services\Ksef;

use App\Models\Ksef\KsefSignalSettings;
use Illuminate\Support\Facades\Http;

/**
 * Wysyłka powiadomień przez bramkę CallMeBot (WhatsApp, HTTPS GET).
 * Bez instalacji na serwerze — pasuje do shared hostingu (jak inne integracje API).
 *
 * UWAGA: CallMeBot WhatsApp NIE przyjmuje polskich znaków ani emoji
 * („invalid characters") — treść jest transliterowana do ASCII przed wysłaniem.
 *
 * Współdzielone przez powiadomienia KSeF i raporty Argo Connect (chatbot).
 *
 * @see https://www.callmebot.com/blog/free-api-whatsapp-messages/
 */
class SignalSender
{
    private const ENDPOINT = 'https://api.callmebot.com/whatsapp.php';

    /**
     * Wysyłka na podstawie globalnej konfiguracji KSeF (numer + apikey).
     *
     * @return array{ok: bool, error: ?string}
     */
    public function send(string $message, ?KsefSignalSettings $settings = null): array
    {
        $settings ??= KsefSignalSettings::current();

        return $this->sendTo($message, $settings->phone, $settings->api_key);
    }

    /**
     * Wysyłka na wskazany numer/apikey (używane też przez raporty Connect).
     *
     * @return array{ok: bool, error: ?string}
     */
    public function sendTo(string $message, ?string $phone, ?string $apiKey): array
    {
        $phone = trim((string) $phone);
        $apiKey = trim((string) $apiKey);

        if ($phone === '' || $apiKey === '') {
            return ['ok' => false, 'error' => 'Uzupełnij numer odbiorcy i apikey CallMeBot.'];
        }

        try {
            $response = Http::timeout(30)->get(self::ENDPOINT, [
                'phone' => $phone,
                'apikey' => $apiKey,
                'text' => $this->toAscii($message),
            ]);

            $body = trim(strip_tags($response->body()));

            // CallMeBot bywa zwraca 2xx z treścią błędu — łapiemy też to.
            // UWAGA (awaria 2026-07-28): przy niedostarczeniu bramka odpowiada HTTP **207**
            // z treścią „Service is down (410)…" — `successful()` przepuszczało to jako sukces,
            // przez co powiadomienia były po cichu gubione (last_sent_date zapisany, wiadomości brak).
            // Dlatego: sukces WYŁĄCZNIE przy HTTP 200 bez znanych fraz błędu.
            $failure = $response->status() !== 200
                || preg_match('/error|service is down|not delivered|invalid|apikey|inconvenience/i', $body) === 1;

            if ($failure) {
                return ['ok' => false, 'error' => $body !== '' ? 'HTTP ' . $response->status() . ': ' . $body : 'Bramka CallMeBot zwróciła błąd (HTTP ' . $response->status() . ').'];
            }

            return ['ok' => true, 'error' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** Transliteracja PL → ASCII (CallMeBot WhatsApp odrzuca polskie znaki); zachowuje nowe linie. */
    private function toAscii(string $s): string
    {
        $map = [
            'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ż' => 'z', 'ź' => 'z',
            'Ą' => 'A', 'Ć' => 'C', 'Ę' => 'E', 'Ł' => 'L', 'Ń' => 'N', 'Ó' => 'O', 'Ś' => 'S', 'Ż' => 'Z', 'Ź' => 'Z',
            '„' => '"', '”' => '"', '–' => '-', '—' => '-', '×' => 'x', '€' => 'EUR',
        ];

        return preg_replace('/[^\x20-\x7E\r\n]/u', '', strtr($s, $map)) ?? '';
    }
}
