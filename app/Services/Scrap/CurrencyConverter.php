<?php

namespace App\Services\Scrap;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Przelicznik walut → EUR po kursie referencyjnym EBC (frankfurter.app) z DNIA POPRZEDNIEGO.
 * Cache 12h. Używane w Argo Scope do normalizacji CENNIKA porównawczego na EUR, gdy nasz cennik
 * jest w innej walucie (np. PLN, CZK) — żeby „Cena cennik" i „Różnica" były porównywalne z konkurentem.
 */
class CurrencyConverter
{
    private const API = 'https://api.frankfurter.app';

    /** Ile EUR za 1 jednostkę waluty $from, wg EBC z dnia poprzedniego. EUR→1.0; null gdy brak/błąd. */
    public function toEur(string $from): ?float
    {
        $from = strtoupper(trim($from));
        if ($from === 'EUR') {
            return 1.0;
        }
        if ($from === '') {
            return null;
        }

        $date = $this->rateDate();

        return Cache::remember("fx:{$from}:EUR:{$date}", now()->addHours(12), function () use ($from, $date) {
            try {
                $res = Http::timeout(8)->get(self::API . "/{$date}", ['from' => $from, 'to' => 'EUR']);
                $rate = $res->ok() ? $res->json('rates.EUR') : null;

                return is_numeric($rate) ? (float) $rate : null;
            } catch (\Throwable) {
                return null;
            }
        });
    }

    /** Ile PLN za 1 jednostkę waluty $from, wg EBC z dnia poprzedniego. PLN→1.0; null gdy brak/błąd. */
    public function toPln(string $from): ?float
    {
        $from = strtoupper(trim($from));
        if ($from === 'PLN') {
            return 1.0;
        }
        if ($from === '') {
            return null;
        }

        $date = $this->rateDate();

        return Cache::remember("fx:{$from}:PLN:{$date}", now()->addHours(12), function () use ($from, $date) {
            try {
                $res = Http::timeout(8)->get(self::API . "/{$date}", ['from' => $from, 'to' => 'PLN']);
                $rate = $res->ok() ? $res->json('rates.PLN') : null;

                return is_numeric($rate) ? (float) $rate : null;
            } catch (\Throwable) {
                return null;
            }
        });
    }

    /** Data kursu = dzień poprzedni (EBC i tak zwróci ostatni dzień roboczy ≤ tej daty). */
    public function rateDate(): string
    {
        return now()->subDay()->format('Y-m-d');
    }
}
