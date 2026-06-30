<?php

namespace App\Services\Connect;

use App\Models\Connect\Order;
use App\Services\Scrap\CurrencyConverter;
use Illuminate\Support\Carbon;

/**
 * Raport sprzedaży (Argo Connect → Integracja chatboot).
 * Liczy sprzedaż z `orders` po dacie złożenia (date_add), per kraj (delivery_country_code),
 * w walucie zamówienia + przeliczenie na PLN (kurs EBC, [[CurrencyConverter]]).
 *
 * „Sprzedaż" = WSZYSTKIE zamówienia złożone w okresie (suma total_amount) — wybór usera.
 *
 * @see \App\Console\Commands\ConnectSalesReport
 * @see \App\Http\Controllers\Admin\Connect\ChatbotController
 */
class SalesReportService
{
    public function __construct(private CurrencyConverter $fx)
    {
    }

    /**
     * Złóż treść raportu z szablonu: {data} {sprzedaz_per_kraj} {razem_dzis} {obrot_tydzien} {obrot_miesiac}.
     *
     * $dayRef = dzień raportu. DOMYŚLNIE = DZIEŃ POPRZEDNI (cron o 00:01 zamyka miniony dzień;
     * raportowanie „dziś" o północy dałoby zero). {data} pokazuje datę raportowanego dnia.
     * „W tym tygodniu/miesiącu" = BIEŻĄCY tydzień kalendarzowy (pon–niedz) i miesiąc względem now(),
     * niezależnie od dnia raportu.
     */
    public function renderTemplate(string $template, ?Carbon $dayRef = null): string
    {
        $dayRef = $dayRef ? $dayRef->copy() : now()->subDay();
        $day = $this->salesByCountry($dayRef->copy()->startOfDay(), $dayRef->copy()->endOfDay());

        $now = now();

        return strtr($template, [
            '{data}' => $dayRef->format('d.m.Y'),
            '{sprzedaz_per_kraj}' => $this->formatCountryLines($day),
            '{razem_dzis}' => $this->formatMoney($this->sumPln($day), 'PLN'),
            '{obrot_tydzien}' => $this->formatMoney($this->totalPln($now->copy()->startOfWeek(), $now->copy()->endOfWeek()), 'PLN'),
            '{obrot_miesiac}' => $this->formatMoney($this->totalPln($now->copy()->startOfMonth(), $now->copy()->endOfMonth()), 'PLN'),
        ]);
    }

    /**
     * Sprzedaż w okresie per kraj: waluty natywne + suma w PLN.
     *
     * @return array<string, array{currencies: array<string,float>, pln: float}>
     */
    public function salesByCountry(Carbon $from, Carbon $to): array
    {
        $rows = Order::query()
            ->whereBetween('date_add', [$from, $to])
            ->selectRaw('UPPER(COALESCE(NULLIF(delivery_country_code, ""), "??")) as cc, UPPER(COALESCE(NULLIF(currency, ""), "PLN")) as cur, SUM(total_amount) as total')
            ->groupBy('cc', 'cur')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[$row->cc]['currencies'][$row->cur] = ($out[$row->cc]['currencies'][$row->cur] ?? 0.0) + (float) $row->total;
        }

        foreach ($out as &$data) {
            $pln = 0.0;
            foreach ($data['currencies'] as $currency => $amount) {
                $pln += $this->toPln((float) $amount, (string) $currency);
            }
            $data['pln'] = $pln;
        }

        return $out;
    }

    /** Łączny obrót w PLN w okresie. */
    public function totalPln(Carbon $from, Carbon $to): float
    {
        return $this->sumPln($this->salesByCountry($from, $to));
    }

    private function sumPln(array $byCountry): float
    {
        return array_sum(array_map(fn ($d) => $d['pln'] ?? 0.0, $byCountry));
    }

    private function toPln(float $amount, string $currency): float
    {
        $rate = $this->fx->toPln($currency);

        return $rate !== null ? $amount * $rate : 0.0;
    }

    /** Linie „KRAJ: kwota [/ kwota PLN]" — PL pierwszy, reszta wg obrotu PLN malejąco. */
    private function formatCountryLines(array $byCountry): string
    {
        if (empty($byCountry)) {
            return '(brak)';
        }

        uksort($byCountry, function ($a, $b) use ($byCountry) {
            if ($a === 'PL') {
                return -1;
            }
            if ($b === 'PL') {
                return 1;
            }

            return ($byCountry[$b]['pln'] ?? 0) <=> ($byCountry[$a]['pln'] ?? 0);
        });

        $lines = [];
        foreach ($byCountry as $cc => $data) {
            $native = [];
            foreach ($data['currencies'] as $currency => $amount) {
                $native[] = $this->formatMoney((float) $amount, (string) $currency);
            }
            $nativeStr = implode(' + ', $native);

            $onlyPln = count($data['currencies']) === 1 && isset($data['currencies']['PLN']);
            $lines[] = $onlyPln
                ? "{$cc}: {$nativeStr}"
                : "{$cc}: {$nativeStr} / " . $this->formatMoney($data['pln'], 'PLN');
        }

        return implode("\n", $lines);
    }

    /** Polski format kwoty: „5 183,60 zł" (PLN) lub „2 500,00 EUR" (inne waluty). */
    public function formatMoney(float $amount, string $currency): string
    {
        $n = number_format($amount, 2, ',', ' ');

        return $currency === 'PLN' ? "{$n} zł" : "{$n} {$currency}";
    }
}
