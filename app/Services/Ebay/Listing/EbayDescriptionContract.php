<?php

namespace App\Services\Ebay\Listing;

/**
 * Walidator kontraktu danych PIM–eBay (spec: `ebay/spec-pim.html`, sierpień 2026).
 *
 * Szablon aukcji „BSP - DE" nie zawiera ŻADNEJ danej technicznej — tabela parametrów na aukcji
 * powstaje z list wewnątrz opisu: CSS zamienia `<li><strong>Marke:</strong> Alfa Romeo</li>`
 * w wiersz tabeli. Zaletą jest zgodność z produktem (wariant aluminiowy sam się opisuje),
 * ceną — że WYGLĄD AUKCJI ZALEŻY OD STRUKTURY DANYCH.
 *
 * Dlatego naruszenia kontraktu nie są kwestią gustu: opis wypisany akapitem z `<br>` zamiast
 * listy renderuje się na aukcji jako zbity blok tekstu, a pozycja bez wartości — jako wiersz
 * z pustą prawą kolumną. Te reguły sprawdzamy maszynowo, przed wystawieniem.
 */
class EbayDescriptionContract
{
    /** Litery występujące wyłącznie w polskim — łapią nieprzetłumaczone wartości atrybutów. */
    private const POLISH_LETTERS = 'ąćęłńóśźżĄĆĘŁŃÓŚŹŻ';

    /** Etykiety bloku nagłówkowego, który szablon aukcji i tak ukrywa CSS-em. */
    private const HEADER_LABELS = ['Hersteller:', 'Art.-Nr.:', 'EAN:', 'Produktzustand:'];

    /**
     * Sprawdź wyrenderowany opis. Zwraca listę naruszeń (pusta = zgodny z kontraktem).
     *
     * @return list<string>
     */
    public static function check(string $html, string $marketplace = 'EBAY_DE'): array
    {
        $html = trim($html);
        if ($html === '') {
            return [];   // pusty opis zgłasza już EbayListingRenderer::problems()
        }

        $doc = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        // Bez tego prologu DOMDocument potraktuje UTF-8 jak ISO-8859-1 i rozsypie polskie znaki,
        // przez co reguła o nieprzetłumaczonych wartościach przestałaby cokolwiek łapać.
        $doc->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $xpath = new \DOMXPath($doc);
        $problems = [];

        self::checkHeaderBlock($xpath, $problems);
        self::checkListStructure($xpath, $problems);
        self::checkListItems($xpath, $problems);
        self::checkLanguage($doc, $marketplace, $problems);

        return array_values(array_unique($problems));
    }

    /** Blok „Hersteller / Art.-Nr. / EAN / Produktzustand" — szablon aukcji pokazuje to z własnych źródeł. */
    private static function checkHeaderBlock(\DOMXPath $xpath, array &$problems): void
    {
        $first = $xpath->query('(//p)[1]')->item(0);
        if (! $first) {
            return;
        }

        $text = $first->textContent;
        $hits = array_filter(self::HEADER_LABELS, fn ($label) => str_contains($text, $label));

        if (count($hits) >= 2) {
            $problems[] = 'opis zaczyna się blokiem '.implode('/', $hits)
                .' — szablon aukcji ukrywa go hackiem CSS, PIM nie powinien go generować';
        }
    }

    /** Dane techniczne muszą być listą; zagnieżdżanie rozwala renderowanie wierszy. */
    private static function checkListStructure(\DOMXPath $xpath, array &$problems): void
    {
        if ($xpath->query('//ul/li')->length === 0) {
            $problems[] = 'brak danych technicznych w <ul>/<li> — na aukcji wyjdzie zbity blok tekstu zamiast tabeli';
        }

        if ($xpath->query('//ul//ul | //ul//ol')->length > 0) {
            $problems[] = 'lista zagnieżdżona w liście — jedna pozycja to jeden parametr';
        }
    }

    /** Wzorzec pozycji: <li><strong>Etykieta:</strong> wartość</li>. */
    private static function checkListItems(\DOMXPath $xpath, array &$problems): void
    {
        foreach ($xpath->query('//ul/li') as $li) {
            /** @var \DOMElement $li */
            $strongs = $li->getElementsByTagName('strong');
            $full = trim(preg_replace('/\s+/u', ' ', $li->textContent) ?? '');

            if ($strongs->length === 0) {
                $problems[] = "pozycja bez etykiety w <strong>: „{$full}”";

                continue;
            }

            $label = trim($strongs->item(0)->textContent);
            $value = trim(mb_substr($full, mb_strlen($label)));

            // Zdanie zamiast pary — spec podaje wprost „Jeder Schutz hat Verstärkungsprägungen".
            if (! str_ends_with($label, ':')) {
                $problems[] = "pozycja zdaniowa zamiast pary etykieta/wartość: „{$full}” — zamień na „Etykieta: wartość”";

                continue;
            }

            if ($value === '') {
                $problems[] = "pozycja „{$label}” bez wartości — pole bez danych ma zniknąć z opisu";

                continue;
            }

            // Zero to nie dana, tylko brak danych. „Schutzdicke: 0.00 mm" mówi kupującemu bzdurę.
            if (preg_match('/^0([.,]0+)?(\s|$)/u', $value)) {
                $problems[] = "pozycja „{$label}” ma wartość zerową („{$value}”) — pomiń ją zamiast wypisywać zero";
            }

            if ($strongs->length > 1) {
                $problems[] = "pozycja „{$label}” ma wartość też w <strong> — etykiety są szare, wartości ciemne, to się rozjedzie";
            }
        }
    }

    /**
     * Wartości atrybutów bywają nieprzetłumaczone i polski tekst jedzie na niemiecką aukcję
     * („Geschützte Unterbodenelemente: silnik, skrzynię biegów"). Szukamy liter, których
     * nie ma w językach rynków docelowych.
     */
    private static function checkLanguage(\DOMDocument $doc, string $marketplace, array &$problems): void
    {
        if (strtoupper($marketplace) === 'EBAY_PL') {
            return;
        }

        $text = $doc->textContent;
        if (preg_match('/[\p{L}]*['.self::POLISH_LETTERS.'][\p{L}]*/u', $text, $m)) {
            $problems[] = "polski tekst w opisie na rynku {$marketplace} (np. „{$m[0]}”) — wartość atrybutu nie została przetłumaczona";
        }
    }
}
