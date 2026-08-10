<?php

/**
 * Seed kanału "et" (estoński) w matrycy tłumaczeń — translation_phrase_renditions.
 *
 * Bliźniak `lv-matrix-seed.php` — patrz `docs/lotwa/README.md`, zasady są te same.
 *
 * Forma nazwy:
 *   „Terasest mootori kaitse Audi A4 B9"  /  „Alumiiniumist käigukasti kaitse Ford Ranger Raptor"
 *
 * Materiał w elatiivie („terasest" = ze stali), element w genitiivie, rdzeń `kaitse` na końcu.
 * Rynek estoński mówi najczęściej „karterikaitse", ale `karter` to miska olejowa — do osłony
 * skrzyni biegów czy chłodnicy ten termin nie pasuje, więc trzymamy skalowalne „<element> kaitse".
 *
 * Użycie:
 *   php deploy/et-matrix-seed.php                # dry-run
 *   php deploy/et-matrix-seed.php --apply
 *   php deploy/et-matrix-seed.php --apply --overwrite
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$opts = getopt('', ['apply', 'overwrite']);
$apply = isset($opts['apply']);
$overwrite = isset($opts['overwrite']);

const CHANNEL = 'et';

/** Fraza PL (phrase_pl z matrycy) → tłumaczenie estońskie. */
$ET = [
    // --- bazowe: stal ---
    'Stalowa osłona silnika'                        => 'Terasest mootori kaitse',
    'Stalowa osłona skrzyni biegów'                 => 'Terasest käigukasti kaitse',
    'Stalowa osłona zbiornika paliwa'               => 'Terasest kütusepaagi kaitse',
    'Stalowa osłona AdBlue'                         => 'Terasest AdBlue paagi kaitse',
    'Stalowa osłona dyferencjału'                   => 'Terasest diferentsiaali kaitse',
    'Stalowa osłona katalizatora'                   => 'Terasest katalüsaatori kaitse',
    'Stalowa osłona chłodnicy'                      => 'Terasest radiaatori kaitse',
    'Stalowa osłona reduktora'                      => 'Terasest reduktori kaitse',
    'Stalowa osłona DPF'                            => 'Terasest DPF-filtri kaitse',
    'Stalowa osłona EGR'                            => 'Terasest EGR-klapi kaitse',
    'Stalowa osłona przedniego zderzaka'            => 'Terasest esistangi kaitse',
    'Stalowa osłona akumulatora'                    => 'Terasest aku kaitse',
    'Stalowa osłona skrzynki transferowej'          => 'Terasest jaotuskasti kaitse',

    // --- bazowe: aluminium ---
    'Aluminiowa osłona silnika'                     => 'Alumiiniumist mootori kaitse',
    'Aluminiowa osłona skrzyni biegów'              => 'Alumiiniumist käigukasti kaitse',
    'Aluminiowa osłona zbiornika paliwa'            => 'Alumiiniumist kütusepaagi kaitse',
    'Aluminiowa osłona dyferencjału'                => 'Alumiiniumist diferentsiaali kaitse',
    'Aluminiowa osłona katalizatora'                => 'Alumiiniumist katalüsaatori kaitse',
    'Aluminiowa osłona chłodnicy'                   => 'Alumiiniumist radiaatori kaitse',
    'Aluminiowa osłona reduktora'                   => 'Alumiiniumist reduktori kaitse',
    'Aluminiowa osłona DPF'                         => 'Alumiiniumist DPF-filtri kaitse',
    'Aluminiowa osłona EGR'                         => 'Alumiiniumist EGR-klapi kaitse',
    'Aluminiowa osłona filtra paliwa'               => 'Alumiiniumist kütusefiltri kaitse',
    'Aluminiowa osłona skrzynki transferowej'       => 'Alumiiniumist jaotuskasti kaitse',
    'Aluminiowa osłona czujnika tylnego wahacza'    => 'Alumiiniumist tagumise õõtshoova anduri kaitse',

    // --- kombinacje elementów (sufiks nominalny PRZED rdzeniem) ---
    'Stalowa osłona silnika i skrzyni biegów'       => 'Terasest mootori ja käigukasti kaitse',
    'Stalowa osłona skrzyni biegów i reduktora'     => 'Terasest käigukasti ja reduktori kaitse',
    'Aluminiowa osłona skrzyni biegów i reduktora'  => 'Alumiiniumist käigukasti ja reduktori kaitse',

    // --- modyfikatory (okoliczniki — po rdzeniu) ---
    'Stalowa osłona silnika galwanizowana'          => 'Terasest mootori kaitse tsinkkattega',
    'Stalowa osłona silnika z Webasto'              => 'Terasest mootori kaitse koos Webastoga',
    'Aluminiowa osłona silnika z Webasto'           => 'Alumiiniumist mootori kaitse koos Webastoga',
    'Stalowa osłona silnika System Start-Stop'      => 'Terasest mootori kaitse Start-Stop süsteemiga',
    'Stalowa osłona EGR System Start-Stop'          => 'Terasest EGR-klapi kaitse Start-Stop süsteemiga',
    'Stalowa osłona katalizatora System Start-Stop' => 'Terasest katalüsaatori kaitse Start-Stop süsteemiga',
];

$bySlug = [];
foreach ($ET as $pl => $et) {
    $bySlug[Str::slug($pl, '_')] = ['pl' => $pl, 'et' => $et];
}

echo "Baza:  " . config('database.connections.' . config('database.default') . '.database') . "\n";
echo "Tryb:  " . ($apply ? 'ZAPIS (--apply)' : 'dry-run (bez zmian)') . ($overwrite ? ' + OVERWRITE' : '') . "\n";
echo "Słownik ET: " . count($bySlug) . " fraz\n\n";

$phrases = DB::table('translation_phrases')->orderByDesc('product_count')->get();
$existing = DB::table('translation_phrase_renditions')
    ->where('channel', CHANNEL)
    ->pluck('value', 'translation_phrase_id');

$stats = ['dodane' => 0, 'nadpisane' => 0, 'bez_zmian' => 0, 'brak_w_slowniku' => 0];
$doZapisu = [];
$braki = [];
$zmiany = [];

foreach ($phrases as $phrase) {
    $entry = $bySlug[$phrase->slug] ?? null;
    if (!$entry) {
        $stats['brak_w_slowniku']++;
        $braki[] = "  {$phrase->slug}  ({$phrase->phrase_pl}, produktów: {$phrase->product_count})";
        continue;
    }

    $current = trim((string) ($existing[$phrase->id] ?? ''));
    if ($current === $entry['et']) {
        $stats['bez_zmian']++;
        continue;
    }
    if ($current !== '' && !$overwrite) {
        $stats['bez_zmian']++;
        continue;
    }

    $current === '' ? $stats['dodane']++ : $stats['nadpisane']++;
    $doZapisu[] = ['id' => $phrase->id, 'value' => $entry['et']];
    $zmiany[] = sprintf('  %-46s → %s', $phrase->phrase_pl, $entry['et']);
}

echo "--- ANALIZA ---\n";
foreach ($stats as $k => $v) {
    echo '  ' . str_pad($k, 18) . $v . "\n";
}
echo '  ' . str_pad('DO ZAPISU', 18) . count($doZapisu) . "\n\n";

if ($zmiany) {
    echo "--- renditcje ET do zapisania ---\n" . implode("\n", $zmiany) . "\n\n";
}
if ($braki) {
    echo "!!! FRAZY BEZ TŁUMACZENIA ET (dopisz do słownika w tym pliku) !!!\n";
    echo implode("\n", $braki) . "\n\n";
}

$nieuzyte = array_diff(array_keys($bySlug), $phrases->pluck('slug')->all());
if ($nieuzyte) {
    echo "(info) Słownik ma frazy nieobecne w tej bazie: " . implode(', ', $nieuzyte) . "\n\n";
}

if (!$apply) {
    echo "DRY-RUN — nic nie zapisano. Dodaj --apply, żeby zapisać.\n";
    exit($braki ? 1 : 0);
}
if (!$doZapisu) {
    echo "Nic do zapisania.\n";
    exit($braki ? 1 : 0);
}

DB::transaction(function () use ($doZapisu) {
    foreach ($doZapisu as $row) {
        DB::table('translation_phrase_renditions')->updateOrInsert(
            ['translation_phrase_id' => $row['id'], 'channel' => CHANNEL],
            ['value' => $row['value'], 'source' => 'manual', 'updated_at' => now(), 'created_at' => now()]
        );
    }
});

$total = DB::table('translation_phrase_renditions')->where('channel', CHANNEL)->where('value', '<>', '')->count();
echo "\nGOTOWE. Zapisanych renditcji: " . count($doZapisu) . "\n";
echo "Renditcji ET w matrycy łącznie: {$total} / " . count($phrases) . " fraz\n";
echo "\nKolejny krok:  php artisan translations:auto-translate\n";

exit($braki ? 1 : 0);
