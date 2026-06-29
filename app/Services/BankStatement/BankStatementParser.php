<?php

namespace App\Services\BankStatement;

interface BankStatementParser
{
    /**
     * Parsuje plik i zwraca listę wierszy w formacie:
     * [
     *   [
     *     'booking_date' => '2026-04-15',
     *     'description'  => 'Opis operacji',
     *     'counterparty' => 'Nazwa kontrahenta' | null,
     *     'amount'       => -123.45,         // ujemne = obciążenie
     *     'direction'    => 'in' | 'out',
     *     'reference'    => 'numer' | null,
     *     'raw_row'      => [...] (oryginalny wiersz),
     *   ],
     *   ...
     * ]
     */
    public function parse(string $filePath): array;
}
