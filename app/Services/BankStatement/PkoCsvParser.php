<?php

namespace App\Services\BankStatement;

/**
 * Typowy eksport PKO BP: CSV z nagłówkiem, przecinek lub średnik, Windows-1250.
 * Nagłówki (po normalizacji):
 *   data_operacji, data_waluty, typ_transakcji, kwota, waluta,
 *   saldo_po_transakcji, opis_transakcji, ...
 * Parser wykrywa kolumny po nazwie — odporny na dodatkowe kolumny/kolejność.
 */
class PkoCsvParser extends BaseCsvParser
{
    public function parse(string $filePath): array
    {
        $lines = $this->readLines($filePath);
        if (empty($lines)) return [];

        $delim = $this->detectDelimiter($lines[0]);
        $header = str_getcsv($lines[0], $delim, '"', '\\');
        $map = [];
        foreach ($header as $i => $h) {
            $map[$this->normalizeHeader($h)] = $i;
        }

        $idxDate     = $map['data_operacji']       ?? $map['data_ksiegowania'] ?? null;
        $idxAmount   = $map['kwota']               ?? null;
        $idxDesc     = $map['opis_transakcji']     ?? $map['opis_operacji']    ?? null;
        $idxType     = $map['typ_transakcji']      ?? $map['typ_operacji']     ?? null;
        $idxCounter  = $map['nazwa_nadawcy']       ?? $map['nazwa_odbiorcy']   ?? $map['kontrahent'] ?? null;
        $idxRef      = $map['numer_operacji']      ?? $map['referencja']       ?? null;

        if ($idxDate === null || $idxAmount === null) {
            // Fallback: jeśli brak dopasowanego nagłówka, użyj parsera Santander-like (indeksy liczbowe).
            return (new SantanderCsvParser())->parse($filePath);
        }

        $rows = [];
        for ($i = 1, $n = count($lines); $i < $n; $i++) {
            $cols = str_getcsv($lines[$i], $delim, '"', '\\');
            if (!isset($cols[$idxDate])) continue;

            $date = $this->parseDate($cols[$idxDate] ?? null);
            if (!$date) continue;

            $amount    = $this->parseAmount($cols[$idxAmount] ?? null);
            $direction = $amount < 0 ? 'out' : 'in';

            $descParts = [];
            if ($idxType !== null && !empty($cols[$idxType])) $descParts[] = trim($cols[$idxType]);
            if ($idxDesc !== null && !empty($cols[$idxDesc])) $descParts[] = trim($cols[$idxDesc]);

            $rows[] = [
                'booking_date' => $date,
                'description'  => implode(' · ', array_filter($descParts)),
                'counterparty' => $idxCounter !== null ? (trim($cols[$idxCounter] ?? '') ?: null) : null,
                'amount'       => $amount,
                'direction'    => $direction,
                'reference'    => $idxRef !== null ? (trim($cols[$idxRef] ?? '') ?: null) : null,
                'raw_row'      => $cols,
            ];
        }

        return $rows;
    }
}
