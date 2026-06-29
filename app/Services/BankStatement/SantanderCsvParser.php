<?php

namespace App\Services\BankStatement;

/**
 * Typowy eksport Santander: CSV bez nagłówka, średnik, kodowanie Windows-1250.
 * Kolumny (kolejność):
 *   0: Data operacji
 *   1: Data księgowania
 *   2: Opis operacji
 *   3: Tytuł / Nazwa kontrahenta
 *   4: Rachunek
 *   5: Kwota
 *   6: Saldo
 *   7: Waluta
 * Jeśli wykryjemy linię nagłówka — pomijamy ją.
 */
class SantanderCsvParser extends BaseCsvParser
{
    public function parse(string $filePath): array
    {
        $lines = $this->readLines($filePath);
        if (empty($lines)) return [];

        $delim = $this->detectDelimiter($lines[0]);
        $rows = [];

        foreach ($lines as $i => $line) {
            $cols = str_getcsv($line, $delim, '"', '\\');
            // pomiń nagłówek jeśli pierwsza kolumna nie wygląda na datę
            if ($i === 0 && !preg_match('/\d{4}-\d{2}-\d{2}|\d{2}[.\/]\d{2}[.\/]\d{4}/', $cols[0] ?? '')) {
                continue;
            }
            if (count($cols) < 6) continue;

            $bookingDate = $this->parseDate($cols[1] ?? $cols[0] ?? null);
            if (!$bookingDate) continue;

            $desc         = trim(($cols[2] ?? '') . ' ' . ($cols[3] ?? ''));
            $counterparty = trim($cols[3] ?? '') ?: null;
            $amount       = $this->parseAmount($cols[5] ?? null);
            $direction    = $amount < 0 ? 'out' : 'in';

            $rows[] = [
                'booking_date' => $bookingDate,
                'description'  => $desc,
                'counterparty' => $counterparty,
                'amount'       => $amount,
                'direction'    => $direction,
                'reference'    => null,
                'raw_row'      => $cols,
            ];
        }

        return $rows;
    }
}
