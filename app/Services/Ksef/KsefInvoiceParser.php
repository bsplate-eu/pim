<?php

namespace App\Services\Ksef;

use SimpleXMLElement;

/**
 * Parser pełnego dokumentu faktury KSeF (schemat FA). Wyciąga to, czego NIE ma w metadanych:
 * pozycje (P_7 — nazwa towaru/usługi) i termin płatności, a także dane do PDF.
 * Xpath po local-name() — odporny na przestrzeń nazw schematu.
 */
class KsefInvoiceParser
{
    /**
     * @return array{
     *   number: ?string, issue_date: ?string, due_date: ?string,
     *   seller: array{name: ?string, nip: ?string}, buyer: array{name: ?string, nip: ?string},
     *   currency: ?string, gross: ?string,
     *   items: array<int, array{name: string, qty: ?string, net: ?string}>, items_text: ?string
     * }
     */
    public static function parse(string $xml): array
    {
        $empty = [
            'number' => null, 'issue_date' => null, 'due_date' => null,
            'seller' => ['name' => null, 'nip' => null], 'buyer' => ['name' => null, 'nip' => null],
            'currency' => null, 'gross' => null, 'items' => [], 'items_text' => null,
        ];

        $x = @simplexml_load_string($xml);
        if (! $x instanceof SimpleXMLElement) {
            return $empty;
        }

        $items = [];
        foreach ($x->xpath("//*[local-name()='FaWiersz']") ?: [] as $wiersz) {
            $name = self::first($wiersz, "*[local-name()='P_7']");
            if ($name === null || $name === '') {
                continue;
            }
            $items[] = [
                'name' => $name,
                'qty' => self::first($wiersz, "*[local-name()='P_8B']"),
                'net' => self::first($wiersz, "*[local-name()='P_11']"),
            ];
        }

        $names = array_map(fn ($i) => $i['name'], $items);

        return [
            'number' => self::firstX($x, "//*[local-name()='Fa']//*[local-name()='P_2']"),
            'issue_date' => self::firstX($x, "//*[local-name()='Fa']//*[local-name()='P_1']"),
            'due_date' => self::firstX($x, "//*[local-name()='TerminPlatnosci']/*[local-name()='Termin']")
                ?? self::firstX($x, "//*[local-name()='Platnosc']//*[local-name()='Termin']"),
            'seller' => [
                'name' => self::firstX($x, "//*[local-name()='Podmiot1']//*[local-name()='Nazwa']"),
                'nip' => self::firstX($x, "//*[local-name()='Podmiot1']//*[local-name()='NIP']"),
            ],
            'buyer' => [
                'name' => self::firstX($x, "//*[local-name()='Podmiot2']//*[local-name()='Nazwa']"),
                'nip' => self::firstX($x, "//*[local-name()='Podmiot2']//*[local-name()='NIP']"),
            ],
            'currency' => self::firstX($x, "//*[local-name()='Fa']//*[local-name()='KodWaluty']"),
            'gross' => self::firstX($x, "//*[local-name()='Fa']//*[local-name()='P_15']"),
            'items' => $items,
            'items_text' => $names ? implode('; ', $names) : null,
        ];
    }

    private static function firstX(SimpleXMLElement $x, string $xpath): ?string
    {
        $nodes = $x->xpath($xpath) ?: [];

        return isset($nodes[0]) ? (trim((string) $nodes[0]) ?: null) : null;
    }

    private static function first(SimpleXMLElement $ctx, string $xpath): ?string
    {
        $nodes = $ctx->xpath($xpath) ?: [];

        return isset($nodes[0]) ? (trim((string) $nodes[0]) ?: null) : null;
    }
}
