<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ExchangeRateController extends Controller
{
    /**
     * Kursy srednie NBP (tabela A) z poprzedniego dnia roboczego dla podanych walut.
     * PLN zawsze rate=1.
     *
     * GET /admin/exchange-rates/nbp?codes=EUR,CZK,PLN
     * Response: { "rates": { "EUR": {"rate": 4.27, "date": "2026-06-02"}, ... } }
     */
    public function nbp(Request $request): JsonResponse
    {
        $codesRaw = (string) $request->query('codes', 'EUR,CZK,PLN');
        $codes = collect(explode(',', $codesRaw))
            ->map(fn ($c) => strtoupper(trim($c)))
            ->filter()
            ->unique()
            ->values();

        $rates = [];
        foreach ($codes as $code) {
            $rates[$code] = $this->getYesterdayRate($code);
        }

        return response()->json(['rates' => $rates]);
    }

    /**
     * Zwraca ['rate' => float, 'date' => 'YYYY-MM-DD'] dla danej waluty albo null.
     * Bierze ostatni opublikowany kurs ze skutecznoscia STRICTLY PRZED dzisiaj
     * (czyli faktyczny "kurs dnia poprzedniego" z perspektywy ksiegowej).
     */
    private function getYesterdayRate(string $code): ?array
    {
        $code = strtoupper($code);

        if ($code === 'PLN') {
            return ['rate' => 1.0, 'date' => date('Y-m-d', strtotime('-1 day'))];
        }

        $cacheKey = "nbp_yesterday_rate_{$code}_" . date('Y-m-d');

        return Cache::remember($cacheKey, now()->addHours(6), function () use ($code) {
            try {
                $url = "https://api.nbp.pl/api/exchangerates/rates/A/{$code}/last/2/";
                $response = Http::timeout(8)->get($url, ['format' => 'json']);

                if (!$response->successful()) {
                    return null;
                }

                $rates = $response->json('rates') ?? [];
                $today = date('Y-m-d');

                // Kandydaci: tylko wpisy z effectiveDate < today.
                $candidates = array_filter(
                    $rates,
                    fn ($r) => isset($r['effectiveDate']) && $r['effectiveDate'] < $today
                );
                usort($candidates, fn ($a, $b) => strcmp($b['effectiveDate'], $a['effectiveDate']));

                if (!empty($candidates)) {
                    return [
                        'rate' => (float) $candidates[0]['mid'],
                        'date' => $candidates[0]['effectiveDate'],
                    ];
                }

                // Fallback: jesli NBP nic wczesniejszego nie zwrocil (np. weekend bez histori),
                // bierzemy najnowszy dostepny kurs.
                if (!empty($rates)) {
                    usort($rates, fn ($a, $b) => strcmp($b['effectiveDate'] ?? '', $a['effectiveDate'] ?? ''));
                    return [
                        'rate' => (float) $rates[0]['mid'],
                        'date' => $rates[0]['effectiveDate'] ?? null,
                    ];
                }

                return null;
            } catch (\Throwable $e) {
                Log::warning("NBP rate fetch failed for {$code}: " . $e->getMessage());
                return null;
            }
        });
    }
}
