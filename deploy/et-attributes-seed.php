<?php

/**
 * Uzupełnia slot "et" w atrybutach i wartościach atrybutów (attributes.name, attribute_values.name).
 *
 * Bliźniak `lv-attributes-seed.php` — zasady i uzasadnienie w `docs/lotwa/README.md`.
 * Bez tego w estońskim opisie ląduje polsko-angielski fallback z popsutym kodowaniem.
 *
 * Użycie:
 *   php deploy/et-attributes-seed.php            # dry-run
 *   php deploy/et-attributes-seed.php --apply
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$opts = getopt('', ['apply']);
$apply = isset($opts['apply']);

const LOCALE = 'et';

/** Nazwy samych atrybutów (klucz = attributes.slug). */
$ATTR_NAMES = [
    'make'       => 'Mark',
    'model'      => 'Mudel',
    'year-start' => 'Tootmisaasta (alates)',
    'year-stop'  => 'Tootmisaasta (kuni)',
    'oil'        => 'Õli väljalaskeava',
    'engine'     => 'Mootor',
    'gearbox'    => 'Käigukast',
    'protection' => 'Kaitstavad osad',
    'material'   => 'Materjal',
];

/** Wartości atrybutu `protection` — PL → ET. */
$PROTECTION = [
    'silnik'                                     => 'mootor',
    'skrzynię biegów'                            => 'käigukast',
    'skrzynia biegów'                            => 'käigukast',
    'chłodnicę'                                  => 'radiaator',
    'chłodnica'                                  => 'radiaator',
    'przedni zderzak'                            => 'esistange',
    'zbiornik paliwa'                            => 'kütusepaak',
    'katalizator'                                => 'katalüsaator',
    'zbiornik paliwa adblue'                     => 'AdBlue paak',
    'zbiornik adblue'                            => 'AdBlue paak',
    'reduktor'                                   => 'reduktor',
    'dyferencjał'                                => 'diferentsiaal',
    'tylny dyferencjał'                          => 'tagumine diferentsiaal',
    'dyferencjał - tylni'                        => 'tagumine diferentsiaal',
    'dyferencjał - przedni'                      => 'eesmine diferentsiaal',
    'przedni mechanizm różnicowy'                => 'eesmine diferentsiaal',
    'egr, system stop-go'                        => 'EGR, START-STOP süsteem',
    'egr'                                        => 'EGR',
    'zawór egr'                                  => 'EGR-klapp',
    'dpf'                                        => 'DPF',
    'dpf , filtr cząstek'                        => 'DPF, tahkete osakeste filter',
    'filtr cząstek'                              => 'tahkete osakeste filter',
    'czujnik filtra cząstek stałych'             => 'tahkete osakeste filtri andur',
    'skrzynia rozdzielcza'                       => 'jaotuskast',
    'elektryczne wspomaganie kierownicy'         => 'elektriline roolivõimendi',
    'silnik elektryczny tylny'                   => 'tagumine elektrimootor',
    'silnik elektryczny - tylni'                 => 'tagumine elektrimootor',
    'silnik elektryczny przedni'                 => 'eesmine elektrimootor',
    'silnik elektryczny - przedni'               => 'eesmine elektrimootor',
    'przewody paliwowe'                          => 'kütusevoolikud',
    'ramę pomocniczą'                            => 'abiraam',
    'filtr paliwa'                               => 'kütusefilter',
    'bateria'                                    => 'aku',
    'montaż możliwy tylko z osłoną silnika'      => 'paigaldus võimalik ainult koos mootorikaitsega',
    'klapka spustu oleju tylko do silników 3.0'  => 'õli väljalaskeluuk ainult 3.0 mootoritele',
    '- położenie oleju nie jest wyrównane z 3,0l' => 'õililuugi asukoht ei ühti 3,0 L mootoriga',
];

/** Wartości atrybutu `engine` — PL → ET. Czego tu nie ma, jest kodem technicznym (kopia PL). */
$ENGINE = [
    'wszystkie benzynowe oraz diesla'   => 'kõik bensiini- ja diiselmootorid',
    'wszystkie manualne skrzynie biegów' => 'kõik manuaalkäigukastid',
    'wszystkie automatyczne skrzynie biegów' => 'kõik automaatkäigukastid',
    'diesel'                            => 'diisel',
    'hybrydowe'                         => 'hübriid',
    'benzyna'                           => 'bensiin',
    'essence'                           => 'bensiin',
    'electric'                          => 'elektriline',
    'manual'                            => 'manuaalne',
    'automat'                           => 'automaatne',
    'automatyczna'                      => 'automaatne',
    '2.0 , petrol'                      => '2.0, bensiin',
    '1.5 diesel'                        => '1.5 diisel',
    '2.2 diesel'                        => '2.2 diisel',
    '1.2 benzyna, 1.5 diesel'           => '1.2 bensiin, 1.5 diisel',
    'diesel 2.5 tdi -  v6'              => 'diisel 2.5 Tdi - V6',
    'benzyna v6,  2.6,  2.8, diesel - 2.5 d' => 'bensiin V6, 2.6, 2.8, diisel - 2.5 D',
    'v6 - automat'                      => 'V6 - automaatne',
    'xp130, wszystkie benzynowe, diesla, hybrydowe' => 'XP130, kõik bensiini-, diisel-, hübriidmootorid',
    'xp150, wszystkie benzynowe, diesla, hybrydowe' => 'XP150, kõik bensiini-, diisel-, hübriidmootorid',
    'nie kompatybilna z: silnikiem v6 - automat' => 'ei sobi V6 mootoriga - automaatne',
    'nie kompatybilna z fiesta st'      => 'ei sobi Fiesta ST-ga',
    'nie kompatybilna z modelami z napędami xdrive' => 'ei sobi XDrive veoga mudelitele',
    'nie kompatybilna z modelem panda 4x4, oraz wersjami z silnikiem diesla'
        => 'ei sobi Panda 4x4 ja diiselmootoriga versioonidele',
    'kompatybilna tylko z modelami: 4x2' => 'sobib ainult 4x2 mudelitele',
];

/**
 * Podmiana POJEDYNCZYCH SŁÓW w wartościach `engine`, których nie ma w słowniku wyżej.
 * Uzasadnienie i zasady — jak w `lv-attributes-seed.php`.
 * Kolejność ma znaczenie: dłuższe wzorce pierwsze („non-hybrid" przed „hybrid").
 */
$ENGINE_WORDS = [
    'pentru toate motorizari' => 'kõikidele mootoritele',
    'wszystkie benzynowe'     => 'kõik bensiini',
    'non-hybrid'              => 'mitte-hübriid',
    'y compris'               => 'kaasa arvatud',
    'hybrydowe'               => 'hübriid',
    'hybrid'                  => 'hübriid',
    'benzynowe'               => 'bensiini',
    'benzyna'                 => 'bensiin',
    'benzina'                 => 'bensiin',
    'benzin'                  => 'bensiin',
    'essence'                 => 'bensiin',
    'petrol'                  => 'bensiin',
    'diesla'                  => 'diisel',
    'diesel'                  => 'diisel',
    'toaate'                  => 'kõik',
    'toate'                   => 'kõik',
    'wszystkie'               => 'kõik',
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

/** Dokłada slot LOCALE do JSON-a, nie ruszając pozostałych locale. */
$dopisz = function (string $json, string $val): ?string {
    $data = json_decode($json, true);
    if (!is_array($data)) {
        return null;
    }
    if (trim((string) ($data[LOCALE] ?? '')) === $val) {
        return null;
    }
    $data[LOCALE] = $val;
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
};

foreach (DB::table('attributes')->get(['id', 'slug', 'name']) as $a) {
    $val = $ATTR_NAMES[$a->slug] ?? null;
    if (!$val) {
        continue;
    }
    $new = $dopisz((string) $a->name, $val);
    if ($new === null) {
        $stats['bez_zmian']++;
        continue;
    }
    $zapisy['attributes'][$a->id] = $new;
    $stats['attr_nazwy']++;
    echo sprintf("  attr  %-12s → %s\n", $a->slug, $val);
}

$wartosci = DB::table('attribute_values as v')
    ->join('attributes as a', 'a.id', '=', 'v.attribute_id')
    ->whereIn('a.slug', ['protection', 'engine'])
    ->get(['v.id', 'v.name', 'a.slug']);

foreach ($wartosci as $v) {
    $data = json_decode((string) $v->name, true);
    $pl = trim((string) ($data['pl'] ?? $data['en'] ?? ''));
    if ($pl === '') {
        continue;
    }
    $klucz = $norm($pl);

    if ($v->slug === 'protection') {
        $val = $PROTECTION[$klucz] ?? null;
        if ($val === null) {
            $braki[] = "  protection: \"{$pl}\" (id={$v->id})";
            continue;
        }
        $licznik = 'protection';
    } elseif (isset($ENGINE[$klucz])) {
        $val = $ENGINE[$klucz];
        $licznik = 'engine_tlumaczone';
    } else {
        $podmieniony = $podmienSlowa($pl, $ENGINE_WORDS);
        if ($podmieniony !== $pl) {
            $val = $podmieniony;
            $licznik = 'engine_slowa';
        } else {
            $val = $pl;                                      // sam kod techniczny — kopia 1:1
            $licznik = 'engine_kopia_pl';
            if (preg_match('/[\p{L}]{4,}/u', $pl)) {
                $doOceny[] = "  \"{$pl}\" (id={$v->id})";
            }
        }
    }

    $new = $dopisz((string) $v->name, $val);
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
    echo "!!! WARTOŚCI 'protection' BEZ TŁUMACZENIA ET (dopisz do słownika) !!!\n";
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
    ->selectRaw("a.slug, COUNT(*) total, SUM(JSON_EXTRACT(v.name, '$." . LOCALE . "') IS NOT NULL) ma_locale")
    ->groupBy('a.slug')->get();

echo "GOTOWE.\n";
foreach ($pokrycie as $p) {
    echo "  {$p->slug}: {$p->ma_locale} / {$p->total} wartości ma slot " . LOCALE . "\n";
}

exit($braki ? 1 : 0);
