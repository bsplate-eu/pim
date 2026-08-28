<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Jednorazowe wgranie sprzedazy 12M z raportu Subiekta.
 *
 * Zrodlo: „Sprzedaz wg asortymentu", FHU Pareto, okres 31.08.2025 - 31.08.2026,
 * grupa „Oslony stalowe" — 503 pozycje, 4883 szt. Plik: database/data/.
 *
 * Dopasowanie symbolu Subiekta do `products.product_code`:
 *  1. trafienie 1:1,
 *  2. po normalizacji (bez spacji i myslnikow, wielkosc liter bez znaczenia) —
 *     tylko gdy klucz wskazuje DOKLADNIE jeden kod w PIM; przy dwuznacznosci
 *     (np. `08.061BLU` vs `08.061-BLU`) pomijamy, zeby nie wpisac liczby na zly kod.
 *
 * Symbole spoza PIM (warianty INOX/DD, `KURIER30KG`, `ZESTAW`) sa pomijane —
 * decyzja uzytkownika: „czego nie ma w PIM, nie bierz".
 */
return new class extends Migration
{
    public function up(): void
    {
        $path = database_path('data/sprzedaz-subiekt-2026-08.csv');

        if (! is_file($path)) {
            return;
        }

        $normalize = static fn (string $s): string => strtoupper(str_replace([' ', '-', "\t"], '', trim($s)));

        $codes = DB::table('products')->distinct()->pluck('product_code')->all();

        $exact = array_flip($codes);
        $normalized = [];
        foreach ($codes as $code) {
            $normalized[$normalize($code)][] = $code;
        }

        $now = Carbon::now();
        $lines = array_slice(file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), 1);

        foreach ($lines as $line) {
            [$symbol, $qty] = array_pad(explode(';', trim($line)), 2, null);
            $symbol = trim((string) $symbol);

            if ($symbol === '' || $qty === null) {
                continue;
            }

            $code = null;
            if (isset($exact[$symbol])) {
                $code = $symbol;
            } else {
                $key = $normalize($symbol);
                if (isset($normalized[$key]) && count($normalized[$key]) === 1) {
                    $code = $normalized[$key][0];
                }
            }

            if ($code === null) {
                continue;
            }

            DB::table('production_items')->updateOrInsert(
                ['product_code' => $code],
                ['sales_12m' => (int) $qty, 'updated_at' => $now],
            );
        }

        // Wiersze wstawione przez updateOrInsert nie dostaja created_at — uzupelniamy.
        DB::table('production_items')->whereNull('created_at')->update(['created_at' => $now]);
    }

    public function down(): void
    {
        DB::table('production_items')->update(['sales_12m' => 0]);
    }
};
