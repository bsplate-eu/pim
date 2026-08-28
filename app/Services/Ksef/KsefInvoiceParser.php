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
     *   bank_account: ?string, bank_accounts: array<int, array<string, string>>,
     *   items: array<int, array{name: string, qty: ?string, net: ?string}>, items_text: ?string
     * }
     */
    public static function parse(string $xml): array
    {
        $empty = [
            'number' => null, 'issue_date' => null, 'due_date' => null,
            'seller' => ['name' => null, 'nip' => null], 'buyer' => ['name' => null, 'nip' => null],
            'currency' => null, 'gross' => null,
            'bank_account' => null, 'bank_accounts' => [],
            'items' => [], 'items_text' => null,
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
        $accounts = self::bankAccounts($x);

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
            'bank_account' => $accounts[0]['nr'] ?? null,
            'bank_accounts' => $accounts,
            'items' => $items,
            'items_text' => $names ? implode('; ', $names) : null,
        ];
    }

    /**
     * Rachunki bankowe z sekcji Platnosc. Bywa ich kilka (np. PLN + walutowy),
     * dlatego zwracamy listę — pierwszy trafia do kolumny „Nr konta", reszta do dymka.
     *
     * @return array<int, array<string, string>>
     */
    private static function bankAccounts(SimpleXMLElement $x): array
    {
        $out = [];
        foreach ($x->xpath("//*[local-name()='Platnosc']//*[local-name()='RachunekBankowy']") ?: [] as $rb) {
            $nr = self::account(self::first($rb, "*[local-name()='NrRB']"));
            if ($nr === null) {
                continue;
            }
            $out[] = array_filter([
                'nr' => $nr,
                'bank' => self::first($rb, "*[local-name()='NazwaBanku']"),
                'swift' => self::first($rb, "*[local-name()='SWIFT']"),
                'opis' => self::first($rb, "*[local-name()='OpisRachunku']"),
            ], fn ($v) => $v !== null && $v !== '');
        }

        return $out;
    }

    /** NrRB bywa zapisany ze spacjami — normalizujemy do ciągu, żeby dało się wkleić do przelewu. */
    private static function account(?string $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $v = mb_strtoupper(preg_replace('/[\s\-]+/u', '', $v) ?? '');

        return $v !== '' ? $v : null;
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
