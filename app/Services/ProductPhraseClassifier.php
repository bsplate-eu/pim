<?php

namespace App\Services;

use Illuminate\Support\Str;

/**
 * Klasyfikator fraz kanonicznych dla osłon podwozia/silnika.
 *
 * Zamiast odejmować markę/model z nazwy (stary stripSuffix — zawodny przy "A4 B9", "Vauxhall Vivaro" itd.),
 * ROZPOZNAJE typ produktu z samych słów technicznych. Nie potrzebuje marek ani modeli — te dokleja osobno
 * ProductTranslationComposer z atrybutów PIM (language-neutral).
 *
 * Każdy produkt sprowadza się do 3 wymiarów:
 *   - materiał:    Stalowa | Aluminiowa
 *   - element:     silnika | skrzyni biegów | dyferencjału | ... (lista ELEMENT_RULES)
 *   - wykończenia: galwanizowana | z Webasto | System Start-Stop (doklejane NA KOŃCU)
 *
 * Szyk frazy:  "{Materiał} osłona {element} {wykończenia}"
 * Przykład:    "Stalowa osłona silnika galwanizowana"
 *
 * Cały katalog (~1500 produktów) sprowadza się do ~33 fraz kanonicznych (rozpoznanie ~99,8%).
 */
class ProductPhraseClassifier
{
    /**
     * Reguły wykrywania ELEMENTU. Kolejność = priorytet: bardziej specyficzne PRZED generycznymi.
     * (np. "skrzyni biegów i reduktora" przed samym "skrzyni biegów"; "silnika" jest ostatnie bo najszersze).
     *
     * @var array<array{0: string, 1: string}>
     */
    private const ELEMENT_RULES = [
        // złożone (dwa elementy naraz)
        ['/skrzyn\w*.*redukt|redukt.*skrzyn\w*/iu',                 'skrzyni biegów i reduktora'],
        ['/silnik\w*.*skrzyn\w*\s+bieg|skrzyn\w*\s+bieg.*silnik/iu', 'silnika i skrzyni biegów'],
        // pojedyncze elementy
        ['/skrzyn\w*\s+bieg/iu',                                    'skrzyni biegów'],
        ['/dyferencjał|dyferencjal|mechanizm\w*\s+r[oó]znic|\bmostu\b/iu', 'dyferencjału'],
        ['/zbiornik\w*\s+paliwa/iu',                                'zbiornika paliwa'],
        ['/adblue/iu',                                              'AdBlue'],
        ['/katalizator/iu',                                         'katalizatora'],
        ['/chłodnic|chlodnic/iu',                                   'chłodnicy'],
        ['/\bDPF\b/iu',                                             'DPF'],
        ['/\bEGR\b/iu',                                             'EGR'],
        ['/redukt/iu',                                              'reduktora'],
        ['/turbospręż|turbosprez|\bturbo\b/iu',                    'turbosprężarki'],
        ['/misk[ai]\s+olej/iu',                                     'miski olejowej'],
        ['/transfer/iu',                                            'skrzynki transferowej'],
        ['/filtr\w*\s+paliwa/iu',                                   'filtra paliwa'],
        ['/zderzak/iu',                                             'przedniego zderzaka'],
        ['/akumulator/iu',                                          'akumulatora'],
        ['/czujnik|wahacz/iu',                                      'czujnika tylnego wahacza'],
        // sam "system Stop-Go / Start-Stop" bez nazwanego elementu = osłona silnika (vany Mercedes W447)
        ['/start[\-\s]?stop|stop[\-\s&;,]+go/iu',                   'silnika'],
        ['/silnik/iu',                                              'silnika'], // najbardziej generyczne — ostatnie
    ];

    /**
     * Reguły wykrywania WYKOŃCZEŃ (doklejane na końcu frazy, w tej kolejności).
     *
     * @var array<array{0: string, 1: string}>
     */
    private const MODIFIER_RULES = [
        ['/galwanizowan/iu',                                        'galwanizowana'],
        ['/webasto/iu',                                             'z Webasto'],
        ['/start[\-\s]?(go|stop)|stop[\-\s&;,]+go/iu',              'System Start-Stop'],
    ];

    private const MATERIAL_ALUMINIUM = '/alumini|aluminium/iu';

    /**
     * Rozpoznaje frazę kanoniczną z polskiej nazwy produktu.
     *
     * @return array{material: string, element: string, modifiers: string[], phrase_pl: string, slug: string}|null
     *         null gdy nie da się rozpoznać elementu (produkt → review queue).
     */
    public function classify(?string $plName): ?array
    {
        $plName = trim((string) $plName);
        if ($plName === '') {
            return null;
        }
        // Dane z importu bywają z zakodowanymi encjami HTML (np. "Stop&amp;Go") — dekoduj przed dopasowaniem.
        $plName = html_entity_decode($plName, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $material = preg_match(self::MATERIAL_ALUMINIUM, $plName) ? 'Aluminiowa' : 'Stalowa';

        $element = null;
        foreach (self::ELEMENT_RULES as [$pattern, $label]) {
            if (preg_match($pattern, $plName)) {
                $element = $label;
                break;
            }
        }
        if ($element === null) {
            return null; // element niejawny → produkt do ręcznej decyzji
        }

        $modifiers = [];
        foreach (self::MODIFIER_RULES as [$pattern, $label]) {
            if (preg_match($pattern, $plName)) {
                $modifiers[] = $label;
            }
        }

        $phrase = $this->buildPhrase($material, $element, $modifiers);

        return [
            'material'  => $material,
            'element'   => $element,
            'modifiers' => $modifiers,
            'phrase_pl' => $phrase,
            'slug'      => Str::slug($phrase, '_'),
        ];
    }

    /**
     * Składa frazę kanoniczną w ustalonym szyku: "{Materiał} osłona {element} {wykończenia}".
     *
     * @param string[] $modifiers
     */
    public function buildPhrase(string $material, string $element, array $modifiers = []): string
    {
        $phrase = $material . ' osłona ' . $element;
        if ($modifiers) {
            $phrase .= ' ' . implode(' ', $modifiers);
        }
        return $phrase;
    }
}
