<?php

namespace App\Services\BankStatement;

abstract class BaseCsvParser implements BankStatementParser
{
    protected function readLines(string $path): array
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            return [];
        }
        // Bank może eksportować w Windows-1250; spróbuj wykryć.
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = @mb_convert_encoding($raw, 'UTF-8', 'Windows-1250');
        }
        // BOM off.
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);

        $lines = preg_split("/\r\n|\n|\r/", $raw) ?: [];
        return array_values(array_filter($lines, fn ($l) => trim($l) !== ''));
    }

    protected function detectDelimiter(string $headerLine): string
    {
        $candidates = [';', ',', "\t", '|'];
        $best = ';';
        $max = 0;
        foreach ($candidates as $c) {
            $count = substr_count($headerLine, $c);
            if ($count > $max) {
                $max = $count;
                $best = $c;
            }
        }
        return $best;
    }

    protected function parseAmount(?string $s): float
    {
        if ($s === null) return 0.0;
        $s = trim($s);
        if ($s === '') return 0.0;
        // Usuń spacje, NBSP, waluty.
        $s = str_replace(["\xc2\xa0", ' ', 'PLN', 'zł'], '', $s);
        // Polski decimal separator: przecinek.
        $s = str_replace(',', '.', $s);
        // Jeśli jest podwójna kropka (tysiące i ułamek), zostaw tylko ostatnią.
        if (substr_count($s, '.') > 1) {
            $pos = strrpos($s, '.');
            $s = str_replace('.', '', substr($s, 0, $pos)) . substr($s, $pos);
        }
        return (float) $s;
    }

    protected function parseDate(?string $s): ?string
    {
        if (!$s) return null;
        $s = trim($s);
        // Obsługa formatów: YYYY-MM-DD, DD.MM.YYYY, DD/MM/YYYY
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m)) {
            return "{$m[1]}-{$m[2]}-{$m[3]}";
        }
        if (preg_match('/^(\d{2})[.\/\-](\d{2})[.\/\-](\d{4})/', $s, $m)) {
            return "{$m[3]}-{$m[2]}-{$m[1]}";
        }
        return null;
    }

    /**
     * Normalizuje nagłówki: lowercase, bez polskich znaków, spacje → podkreślenia.
     */
    protected function normalizeHeader(string $h): string
    {
        $h = mb_strtolower(trim($h));
        $map = ['ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ż'=>'z','ź'=>'z'];
        $h = strtr($h, $map);
        $h = preg_replace('/[^a-z0-9]+/', '_', $h);
        return trim($h, '_');
    }
}
