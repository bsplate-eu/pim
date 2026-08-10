<?php

/**
 * Uzupełnia slot "lv" w atrybutach i wartościach atrybutów (attributes.name, attribute_values.name).
 *
 * Po co: szablon opisu i connector wypisują atrybuty w locale sklepu. Bez slotu `lv` Spatie
 * schodzi do fallbacku i w łotewskim opisie ląduje polsko-angielska mieszanka
 * („Aizsargājamie šasijas elementi: silnik, skrzyni?? bieg??w, Radiator").
 *
 * Zakres: atrybuty widoczne w szablonie/specyfikacji — `protection`, `engine` + nazwy atrybutów.
 * Marki i modele (`make`, `model`) to nazwy własne — nie tłumaczymy.
 *
 * Zasady:
 *  - MERGE PER SLOT: dopisujemy wyłącznie klucz "lv", reszta JSON-a nietknięta,
 *  - mapowanie po znormalizowanej wartości PL (trim + lowercase), więc duplikaty PL dostają to samo LV,
 *  - `engine` bez wpisu w słowniku = kod techniczny (np. „1.6,1.8 Turbo, 2.0, 1.9 TDI") → kopiujemy PL 1:1,
 *    bo taki zapis jest identyczny we wszystkich językach, a kopia zdejmuje fallback do popsutego `en`,
 *  - `protection` bez wpisu = głośny raport (to realne słowa, muszą mieć tłumaczenie),
 *  - domyślnie dry-run; zapis dopiero z --apply.
 *
 * Użycie:
 *   php deploy/lv-attributes-seed.php            # dry-run
 *   php deploy/lv-attributes-seed.php --apply
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$opts = getopt('', ['apply']);
$apply = isset($opts['apply']);

/** Nazwy samych atrybutów (klucz = attributes.slug). */
$ATTR_NAMES = [
    'make'       => 'Marka',
    'model'      => 'Modelis',
    'year-start' => 'Ražošanas gads (no)',
    'year-stop'  => 'Ražošanas gads (līdz)',
    'oil'        => 'Eļļas noliešanas atvere',
    'engine'     => 'Dzinējs',
    'gearbox'    => 'Ātrumkārba',
    'protection' => 'Aizsargājamie elementi',
    'material'   => 'Materiāls',
];

/** Wartości atrybutu `protection` — PL → LV. */
$PROTECTION = [
    'silnik'                                     => 'dzinējs',
    'skrzynię biegów'                            => 'ātrumkārba',
    'skrzynia biegów'                            => 'ātrumkārba',
    'chłodnicę'                                  => 'radiators',
    'chłodnica'                                  => 'radiators',
    'przedni zderzak'                            => 'priekšējais bamperis',
    'zbiornik paliwa'                            => 'degvielas tvertne',
    'katalizator'                                => 'katalizators',
    'zbiornik paliwa adblue'                     => 'AdBlue tvertne',
    'zbiornik adblue'                            => 'AdBlue tvertne',
    'reduktor'                                   => 'reduktors',
    'dyferencjał'                                => 'diferenciālis',
    'tylny dyferencjał'                          => 'aizmugurējais diferenciālis',
    'dyferencjał - tylni'                        => 'aizmugurējais diferenciālis',
    'dyferencjał - przedni'                      => 'priekšējais diferenciālis',
    'przedni mechanizm różnicowy'                => 'priekšējais diferenciālis',
    'egr, system stop-go'                        => 'EGR, START-STOP sistēma',
    'egr'                                        => 'EGR',
    'zawór egr'                                  => 'EGR vārsts',
    'dpf'                                        => 'DPF',
    'dpf , filtr cząstek'                        => 'DPF, cieto daļiņu filtrs',
    'filtr cząstek'                              => 'cieto daļiņu filtrs',
    'czujnik filtra cząstek stałych'             => 'cieto daļiņu filtra sensors',
    'skrzynia rozdzielcza'                       => 'sadales kārba',
    'elektryczne wspomaganie kierownicy'         => 'elektriskais stūres pastiprinātājs',
    'silnik elektryczny tylny'                   => 'aizmugurējais elektromotors',
    'silnik elektryczny - tylni'                 => 'aizmugurējais elektromotors',
    'silnik elektryczny przedni'                 => 'priekšējais elektromotors',
    'silnik elektryczny - przedni'               => 'priekšējais elektromotors',
    'przewody paliwowe'                          => 'degvielas vadi',
    'ramę pomocniczą'                            => 'palīgrāmis',
    'filtr paliwa'                               => 'degvielas filtrs',
    'bateria'                                    => 'akumulators',
    'montaż możliwy tylko z osłoną silnika'      => 'montāža iespējama tikai kopā ar dzinēja aizsargu',
    'klapka spustu oleju tylko do silników 3.0'  => 'eļļas noliešanas lūka tikai 3.0 dzinējiem',
    '- położenie oleju nie jest wyrównane z 3,0l' => 'eļļas lūkas novietojums nesakrīt ar 3,0 L dzinēju',
];

/** Wartości atrybutu `engine` — PL → LV. Czego tu nie ma, jest kodem technicznym (kopia PL). */
$ENGINE = [
    'wszystkie benzynowe oraz diesla'   => 'visi benzīna un dīzeļa',
    'wszystkie manualne skrzynie biegów' => 'visas manuālās ātrumkārbas',
    'wszystkie automatyczne skrzynie biegów' => 'visas automātiskās ātrumkārbas',
    'diesel'                            => 'dīzelis',
    'hybrydowe'                         => 'hibrīda',
    'benzyna'                           => 'benzīns',
    'essence'                           => 'benzīns',
    'electric'                          => 'elektriskais',
    'manual'                            => 'manuālā',
    'automat'                           => 'automātiskā',
    'automatyczna'                      => 'automātiskā',
    '2.0 , petrol'                      => '2.0, benzīns',
    '1.5 diesel'                        => '1.5 dīzelis',
    '2.2 diesel'                        => '2.2 dīzelis',
    '1.2 benzyna, 1.5 diesel'           => '1.2 benzīns, 1.5 dīzelis',
    'diesel 2.5 tdi -  v6'              => 'dīzelis 2.5 Tdi - V6',
    'benzyna v6,  2.6,  2.8, diesel - 2.5 d' => 'benzīns V6, 2.6, 2.8, dīzelis - 2.5 D',
    'v6 - automat'                      => 'V6 - automātiskā',
    'xp130, wszystkie benzynowe, diesla, hybrydowe' => 'XP130, visi benzīna, dīzeļa, hibrīda',
    'xp150, wszystkie benzynowe, diesla, hybrydowe' => 'XP150, visi benzīna, dīzeļa, hibrīda',
    'nie kompatybilna z: silnikiem v6 - automat' => 'nav savietojams ar V6 dzinēju - automātiskā',
    'nie kompatybilna z fiesta st'      => 'nav savietojams ar Fiesta ST',
    'nie kompatybilna z modelami z napędami xdrive' => 'nav savietojams ar XDrive piedziņas modeļiem',
    'nie kompatybilna z modelem panda 4x4, oraz wersjami z silnikiem diesla'
        => 'nav savietojams ar Panda 4x4 un dīzeļa dzinēja versijām',
    'kompatybilna tylko z modelami: 4x2' => 'savietojams tikai ar 4x2 modeļiem',
];

/**
 * Podmiana POJEDYNCZYCH SŁÓW w wartościach `engine`, których nie ma w słowniku wyżej.
 *
 * Produkcja ma sporo wartości będących kombinacją kodów silnika z wtrąconym słowem
 * („1.9 PD TDI, 2.0 TDI, 1.6, 2.0 benzin", „3.0 diesel", „pentru toate motorizari RS").
 * Wypisywanie każdej kombinacji osobno nie ma końca — podmieniamy same słowa, więc nowe
 * kombinacje z feedu też się przetłumaczą.
 *
 * Kolejność ma znaczenie: dłuższe wzorce idą pierwsze, żeby „non-hybrid" wygrał z „hybrid".
 */
$ENGINE_WORDS = [
    'pentru toate motorizari' => 'visiem dzinējiem',   // rumuński ogon z feedu
    'wszystkie benzynowe'     => 'visi benzīna',
    'non-hybrid'              => 'nav hibrīds',
    'y compris'               => 'ieskaitot',
    'hybrydowe'               => 'hibrīda',
    'hybrid'                  => 'hibrīds',
    'benzynowe'               => 'benzīna',
    'benzyna'                 => 'benzīns',
    'benzina'                 => 'benzīns',
    'benzin'                  => 'benzīns',
    'essence'                 => 'benzīns',
    'petrol'                  => 'benzīns',
    'diesla'                  => 'dīzeļa',
    'diesel'                  => 'dīzelis',
    'toaate'                  => 'visi',               // literówka w danych (rum. „toate")
    'toate'                   => 'visi',
    'wszystkie'               => 'visi',
];

$norm = fn (string $s): string => mb_strtolower(trim($s), 'UTF-8');

/** Podmienia słowa z listy, z granicami wyrazu i bez ruszania kodów typu „2.0TFSI". */
$podmienSlowa = function (string $tekst, array $mapa): string {
    foreach ($mapa as $od => $na) {
        $tekst = preg_replace('/(?<![\p{L}\d])' . preg_quote($od, '/') . '(?![\p{L}\d])/iu', $na, $tekst);
    }
    return $tekst;
};

echo "Baza:  " . config('database.connections.' . config('database.default') . '.database') . "\n";
echo "Tryb:  " . ($apply ? 'ZAPIS (--apply)' : 'dry-run (bez zmian)') . "\n\n";

$stats = ['attr_nazwy' => 0, 'protection' => 0, 'engine_tlumaczone' => 0,
          'engine_slowa' => 0, 'engine_kopia_pl' => 0, 'bez_zmian' => 0];
$braki = [];
$doOceny = [];   // wartości engine skopiowane 1:1 mimo że zawierają słowa — do przejrzenia okiem
$zapisy = ['attributes' => [], 'attribute_values' => []];

/** Dokłada slot lv do JSON-a, nie ruszając pozostałych locale. */
$dopiszLv = function (string $json, string $lv): ?string {
    $data = json_decode($json, true);
    if (!is_array($data)) {
        return null; // nie JSON — nie ruszamy
    }
    if (trim((string) ($data['lv'] ?? '')) === $lv) {
        return null; // już jest
    }
    $data['lv'] = $lv;
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
};

// --- nazwy atrybutów ---
foreach (DB::table('attributes')->get(['id', 'slug', 'name']) as $a) {
    $lv = $ATTR_NAMES[$a->slug] ?? null;
    if (!$lv) {
        continue;
    }
    $new = $dopiszLv((string) $a->name, $lv);
    if ($new === null) {
        $stats['bez_zmian']++;
        continue;
    }
    $zapisy['attributes'][$a->id] = $new;
    $stats['attr_nazwy']++;
    echo sprintf("  attr  %-12s → %s\n", $a->slug, $lv);
}

// --- wartości atrybutów ---
$wartosci = DB::table('attribute_values as v')
    ->join('attributes as a', 'a.id', '=', 'v.attribute_id')
    ->whereIn('a.slug', ['protection', 'engine'])
    ->get(['v.id', 'v.name', 'a.slug']);

foreach ($wartosci as $v) {
    $data = json_decode((string) $v->name, true);
    // Kilka wartości nie ma slotu `pl` (weszły tylko po angielsku) — wtedy bierzemy `en` jako źródło.
    $pl = trim((string) ($data['pl'] ?? $data['en'] ?? ''));
    if ($pl === '') {
        continue;
    }
    $klucz = $norm($pl);

    if ($v->slug === 'protection') {
        $lv = $PROTECTION[$klucz] ?? null;
        if ($lv === null) {
            $braki[] = "  protection: \"{$pl}\" (id={$v->id})";
            continue;
        }
        $licznik = 'protection';
    } elseif (isset($ENGINE[$klucz])) {
        $lv = $ENGINE[$klucz];
        $licznik = 'engine_tlumaczone';
    } else {
        $podmieniony = $podmienSlowa($pl, $ENGINE_WORDS);
        if ($podmieniony !== $pl) {
            $lv = $podmieniony;
            $licznik = 'engine_slowa';
        } else {
            $lv = $pl;                                       // sam kod techniczny — kopia 1:1
            $licznik = 'engine_kopia_pl';
            if (preg_match('/[\p{L}]{4,}/u', $pl)) {
                $doOceny[] = "  \"{$pl}\" (id={$v->id})";
            }
        }
    }

    $new = $dopiszLv((string) $v->name, $lv);
    if ($new === null) {
        $stats['bez_zmian']++;
        continue;
    }
    $zapisy['attribute_values'][$v->id] = $new;
    $stats[$licznik]++;
}

echo "\n--- ANALIZA ---\n";
foreach ($stats as $k => $val) {
    echo '  ' . str_pad($k, 20) . $val . "\n";
}
echo '  ' . str_pad('DO ZAPISU', 20)
    . (count($zapisy['attributes']) + count($zapisy['attribute_values'])) . "\n\n";

if ($braki) {
    echo "!!! WARTOŚCI 'protection' BEZ TŁUMACZENIA LV (dopisz do słownika) !!!\n";
    echo implode("\n", $braki) . "\n\n";
}
if ($doOceny) {
    echo "(do oceny) 'engine' skopiowane 1:1 mimo że zawierają słowa — sprawdź, czy to na pewno\n";
    echo "kody/nazwy własne, a nie coś do przetłumaczenia:\n";
    echo implode("\n", array_unique($doOceny)) . "\n\n";
}

if (!$apply) {
    echo "DRY-RUN — nic nie zapisano. Dodaj --apply, żeby zapisać.\n";
    exit($braki ? 1 : 0);
}

DB::transaction(function () use ($zapisy) {
    foreach ($zapisy as $tabela => $wiersze) {
        foreach ($wiersze as $id => $json) {
            DB::table($tabela)->where('id', $id)->update(['name' => $json]);
        }
    }
});

$pokrycie = DB::table('attribute_values as v')->join('attributes as a', 'a.id', '=', 'v.attribute_id')
    ->whereIn('a.slug', ['protection', 'engine'])
    ->selectRaw("a.slug, COUNT(*) total, SUM(JSON_EXTRACT(v.name, '$.lv') IS NOT NULL) ma_lv")
    ->groupBy('a.slug')->get();

echo "GOTOWE.\n";
foreach ($pokrycie as $p) {
    echo "  {$p->slug}: {$p->ma_lv} / {$p->total} wartości ma slot lv\n";
}

exit($braki ? 1 : 0);
