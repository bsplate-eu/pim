<?php

namespace App\Services\Ksef;

use App\Models\Ksef\KsefInvoice;
use Illuminate\Support\Carbon;

/**
 * „Do zapłaty" — sumy niezapłaconych faktur KSeF wg terminu płatności.
 *
 * Źródło: ksef_invoices (to, co widać w zakładce KSeF). Liczymy tylko FV
 * niezapłacone (status = unpaid) i z USTAWIONYM terminem (due_date ≠ null).
 * FV bez terminu = płacone gotówką — pomijamy.
 *
 * Okresy są kalendarzowe i ROZŁĄCZNE od zaległości:
 *  - „dzis"    = due_date dokładnie dzisiaj (bez zaległych),
 *  - „tydzien" = due_date w bieżącym tygodniu kalendarzowym (pon–niedz),
 *  - „miesiac" = due_date w bieżącym miesiącu kalendarzowym.
 *
 * Wynik rozbity per firma (pareto/bsp) i per waluta.
 *
 * @see \App\Http\Controllers\Admin\HomeController dashboard (kafelek)
 */
class DuePaymentsService
{
    /** Firmy obsługiwane (klucz w DB => etykieta na kafelku/w powiadomieniu). */
    public const COMPANIES = [
        'pareto' => 'PARETO',
        'bsp' => 'BSP',
    ];

    /**
     * Dane gotowe dla dashboardu (kafelek): etykiety firm + sumy dla 3 okresów.
     */
    public function forDashboard(?Carbon $now = null): array
    {
        return [
            'companies' => self::COMPANIES,
            'totals' => $this->totals($now),
        ];
    }

    /**
     * Sumy dla wszystkich 3 okresów.
     *
     * @return array<string, array<string, array<string, float>>>
     *   ['dzis' => ['pareto' => ['PLN' => 1234.0, 'EUR' => 5.0], 'bsp' => [...]], 'tydzien' => ..., 'miesiac' => ...]
     */
    public function totals(?Carbon $now = null): array
    {
        $now = $now ? $now->copy() : now();

        return [
            'dzis' => $this->sumInRange($now->copy()->startOfDay(), $now->copy()->endOfDay()),
            'tydzien' => $this->sumInRange($now->copy()->startOfWeek(), $now->copy()->endOfWeek()),
            'miesiac' => $this->sumInRange($now->copy()->startOfMonth(), $now->copy()->endOfMonth()),
        ];
    }

    /**
     * Suma „do zapłaty" w danym okresie, per firma i per waluta.
     * Pusty wynik dla firmy oznacza brak FV (kwota 0).
     *
     * @return array<string, array<string, float>>
     */
    public function sumInRange(Carbon $from, Carbon $to): array
    {
        $rows = KsefInvoice::query()
            ->where('status', 'unpaid')
            ->whereNotNull('due_date')
            ->whereIn('company', array_keys(self::COMPANIES))
            ->whereBetween('due_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('company, currency, SUM(amount) as total')
            ->groupBy('company', 'currency')
            ->get();

        $result = array_fill_keys(array_keys(self::COMPANIES), []);

        foreach ($rows as $row) {
            $currency = $row->currency ?: 'PLN';
            $result[$row->company][$currency] = (float) $row->total;
        }

        return $result;
    }

    /**
     * Kwoty „na dziś" sformatowane per firma — pod treść powiadomienia Signal.
     *
     * @return array<string, array{label: string, text: string}>
     *   ['pareto' => ['label' => 'PARETO', 'text' => '5 183,60 zł'], 'bsp' => ['label' => 'BSP', 'text' => '0,00 zł']]
     */
    public function todayByCompanyFormatted(?Carbon $now = null): array
    {
        $now = $now ? $now->copy() : now();
        $today = $this->sumInRange($now->copy()->startOfDay(), $now->copy()->endOfDay());

        $out = [];
        foreach (self::COMPANIES as $key => $label) {
            $out[$key] = ['label' => $label, 'text' => $this->formatCurrencyMap($today[$key] ?? [])];
        }

        return $out;
    }

    /** Złóż treść powiadomienia z szablonu: {pareto} {bsp} {data}. */
    public function renderTemplate(string $template, ?Carbon $now = null): string
    {
        $now = $now ? $now->copy() : now();
        $companies = $this->todayByCompanyFormatted($now);

        return strtr($template, [
            '{pareto}' => $companies['pareto']['text'] ?? '',
            '{bsp}' => $companies['bsp']['text'] ?? '',
            '{data}' => $now->format('d.m.Y'),
        ]);
    }

    /** Mapę walut → tekst „1 234,56 zł + 50,00 EUR" (PLN pierwsze; pusto → „0,00 zł"). */
    public function formatCurrencyMap(array $map): string
    {
        if (empty($map)) {
            return $this->formatMoney(0, 'PLN');
        }

        uksort($map, fn ($a, $b) => $a === 'PLN' ? -1 : ($b === 'PLN' ? 1 : strcmp($a, $b)));

        $parts = [];
        foreach ($map as $currency => $amount) {
            $parts[] = $this->formatMoney((float) $amount, (string) $currency);
        }

        return implode(' + ', $parts);
    }

    /** Polski format kwoty: „5 183,60 zł" (PLN) lub „50,00 EUR" (inne waluty). */
    public function formatMoney(float $amount, string $currency): string
    {
        $n = number_format($amount, 2, ',', ' ');

        return $currency === 'PLN' ? "{$n} zł" : "{$n} {$currency}";
    }
}
