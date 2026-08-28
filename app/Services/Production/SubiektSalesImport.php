<?php

namespace App\Services\Production;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Wgrywa sprzedaz z raportu Subiekta („Sprzedaz wg asortymentu") do
 * `production_items.sales_12m`, po kodzie produkcyjnym.
 *
 * Logika siedzi tutaj, a nie w migracji, bo import juz sie powtorzyl: pierwszy
 * raport byl zawezony do grupy „Oslony stalowe" i gubil czesc dokumentow.
 * Kolejny plik = kolejna migracja wolajaca `run()`, bez przepisywania regul.
 */
class SubiektSalesImport
{
    /**
     * Symbole Subiekta, ktore w PIM sa tym samym kodem co cos innego.
     * `06.048 DD` (nr OE 7717101210) to wg uzytkownika to samo, co `06.048`,
     * wiec jego sztuki dolicza sie do kodu bazowego.
     */
    public const ALIASES = [
        '06.048 DD' => '06.048',
    ];

    /**
     * @return array{plik:string, wierszy:int, kodow:int, sztuk:int, pominietych:int, pominiete_sztuki:int, pominiete:array<string,int>}
     */
    public function run(string $path): array
    {
        $rows = $this->readCsv($path);
        $codes = DB::table('products')->distinct()->pluck('product_code')->all();

        $exact = array_flip($codes);
        $byNormalized = [];
        foreach ($codes as $code) {
            $byNormalized[$this->normalize($code)][] = $code;
        }

        $perCode = [];
        $skipped = [];

        foreach ($rows as [$symbol, $qty]) {
            $code = $this->resolve($symbol, $exact, $byNormalized);

            if ($code === null) {
                $skipped[$symbol] = ($skipped[$symbol] ?? 0) + $qty;
                continue;
            }

            // Suma, nie nadpisanie — po aliasach kilka symboli moze wpasc na jeden kod.
            $perCode[$code] = ($perCode[$code] ?? 0) + $qty;
        }

        $now = Carbon::now();

        // Zerujemy przed zapisem: import zastepuje poprzedni, a kod, ktory wypadl
        // z raportu, ma pokazac 0, nie stara wartosc.
        DB::table('production_items')->where('sales_12m', '<>', 0)->update(['sales_12m' => 0, 'updated_at' => $now]);

        foreach ($perCode as $code => $qty) {
            DB::table('production_items')->updateOrInsert(
                ['product_code' => $code],
                ['sales_12m' => $qty, 'updated_at' => $now],
            );
        }

        DB::table('production_items')->whereNull('created_at')->update(['created_at' => $now]);

        return [
            'plik' => basename($path),
            'wierszy' => count($rows),
            'kodow' => count($perCode),
            'sztuk' => array_sum($perCode),
            'pominietych' => count($skipped),
            'pominiete_sztuki' => array_sum($skipped),
            'pominiete' => $skipped,
        ];
    }

    /**
     * Symbol Subiekta -> kod PIM. Kolejno: alias, trafienie 1:1, normalizacja.
     * Normalizacja wchodzi tylko gdy klucz wskazuje DOKLADNIE jeden kod —
     * przy dwuznacznosci (`08.061BLU` vs `08.061-BLU`) wolimy pominac niz zgadnac.
     *
     * @param  array<string,int>  $exact
     * @param  array<string,list<string>>  $byNormalized
     */
    private function resolve(string $symbol, array $exact, array $byNormalized): ?string
    {
        $symbol = self::ALIASES[$symbol] ?? $symbol;

        if (isset($exact[$symbol])) {
            return $symbol;
        }

        $key = $this->normalize($symbol);

        return isset($byNormalized[$key]) && count($byNormalized[$key]) === 1
            ? $byNormalized[$key][0]
            : null;
    }

    private function normalize(string $value): string
    {
        return strtoupper(str_replace([' ', '-', "\t"], '', trim($value)));
    }

    /** @return list<array{0:string,1:int}> */
    private function readCsv(string $path): array
    {
        $out = [];

        foreach (array_slice(file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), 1) as $line) {
            [$symbol, $qty] = array_pad(explode(';', trim($line)), 2, null);
            $symbol = trim((string) $symbol);

            if ($symbol !== '' && $qty !== null) {
                $out[] = [$symbol, (int) $qty];
            }
        }

        return $out;
    }
}
