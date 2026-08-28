<?php

namespace App\Services\Ksef;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Kurs średni NBP (tabela A) do przeliczania kosztów w walucie obcej na PLN.
 *
 * Reguła podatkowa (art. 15 ust. 1 ustawy o CIT): koszt przelicza się po kursie średnim NBP
 * z OSTATNIEGO DNIA ROBOCZEGO POPRZEDZAJĄCEGO dzień poniesienia kosztu. Dlatego szukamy
 * od `date - 1 dzień` wstecz — NBP nie publikuje tabel w weekendy i święta (HTTP 404).
 *
 * Kurs zapisujemy na rekordzie (`fx_rate` + `fx_date`), żeby kwota w PLN nigdy się nie zmieniła.
 */
class NbpRateService
{
    private const API = 'https://api.nbp.pl/api/exchangerates/rates/a';

    /** Ile dni wstecz szukamy tabeli (najdłuższa przerwa świąteczna to ~4 dni). */
    private const MAX_LOOKBACK = 10;

    /**
     * @return array{rate: float, date: string}|null  null = waluta nieznana NBP albo API nie odpowiada
     */
    public function rateFor(string $currency, Carbon|string $costDate): ?array
    {
        $currency = strtoupper(trim($currency));
        if ($currency === '' || $currency === 'PLN') {
            return ['rate' => 1.0, 'date' => Carbon::parse($costDate)->toDateString()];
        }

        $day = Carbon::parse($costDate)->startOfDay();

        for ($i = 0; $i < self::MAX_LOOKBACK; $i++) {
            $day = $day->copy()->subDay(); // pierwszy krok: dzień PRZED datą kosztu
            $found = $this->midFor($currency, $day->toDateString());
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /** Przelicza kwotę na PLN; zwraca też użyty kurs, żeby dało się go zapisać. */
    public function toPln(float $amount, string $currency, Carbon|string $costDate): ?array
    {
        $fx = $this->rateFor($currency, $costDate);
        if ($fx === null) {
            return null;
        }

        return [
            'amount_pln' => round($amount * $fx['rate'], 2),
            'fx_rate' => $fx['rate'],
            'fx_date' => $fx['date'],
        ];
    }

    /** Jedna tabela NBP; 404 = dzień wolny, każdy inny błąd też traktujemy jako brak. */
    private function midFor(string $currency, string $date): ?array
    {
        $key = "nbp:a:{$currency}:{$date}";

        // Kursy historyczne się nie zmieniają — trzymamy je długo. Brak tabeli (dzień wolny)
        // cache'ujemy jako 'none', żeby nie pytać NBP o tę samą sobotę w kółko.
        $cached = Cache::get($key);
        if ($cached === 'none') {
            return null;
        }
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $res = Http::timeout(8)->get(self::API . '/' . strtolower($currency) . '/' . $date . '/', ['format' => 'json']);
        } catch (\Throwable $e) {
            return null; // sieć padła — NIE cache'ujemy, spróbujemy jeszcze raz
        }

        if ($res->status() === 404) {
            Cache::put($key, 'none', now()->addDays(30));

            return null;
        }
        if (! $res->ok()) {
            return null;
        }

        $mid = $res->json('rates.0.mid');
        $eff = $res->json('rates.0.effectiveDate');
        if (! is_numeric($mid)) {
            return null;
        }

        $out = ['rate' => (float) $mid, 'date' => $eff ?: $date];
        Cache::put($key, $out, now()->addDays(365));

        return $out;
    }
}
