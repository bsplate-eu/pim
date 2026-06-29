<?php

namespace App\Services\Scrap;

use GuzzleHttp\Client;

/**
 * Wspólny klient HTTP dla driverów scrapujących (Guzzle + retry). Goły HTTP — sklepy konkurenta
 * (lokopi, scut-motor.ro) nie mają agresywnego anti-bota. Uprzejmość (opóźnienia) po stronie driverów.
 */
trait ScrapHttp
{
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';

    private ?Client $httpClient = null;

    private function http(): Client
    {
        return $this->httpClient ??= new Client([
            'timeout' => 30,
            'connect_timeout' => 10,
            'http_errors' => false,
            'headers' => [
                'User-Agent' => self::UA,
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'de-DE,de;q=0.8,hu;q=0.7,ro;q=0.7,en;q=0.6',
            ],
        ]);
    }

    /** GET z retry (429/5xx + błędy sieci). Zwraca body lub null. */
    private function get(string $url, int $attempts = 3): ?string
    {
        for ($i = 1; $i <= $attempts; $i++) {
            try {
                $res = $this->http()->get($url);
                $code = $res->getStatusCode();
                if ($code === 200) {
                    return (string) $res->getBody();
                }
                if ($code === 404 || $code === 410) {
                    return null;
                }
            } catch (\Throwable) {
                // błąd sieci — ponów
            }
            if ($i < $attempts) {
                usleep(500000 * $i);
            }
        }

        return null;
    }
}
