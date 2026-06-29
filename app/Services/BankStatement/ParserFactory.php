<?php

namespace App\Services\BankStatement;

use InvalidArgumentException;

class ParserFactory
{
    public static function make(string $bank): BankStatementParser
    {
        return match ($bank) {
            'santander' => new SantanderCsvParser(),
            'pko'       => new PkoCsvParser(),
            default     => throw new InvalidArgumentException("Nieznany bank: {$bank}"),
        };
    }
}
