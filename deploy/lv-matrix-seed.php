<?php

/**
 * Seed kanału "lv" (łotewski) w matrycy tłumaczeń — translation_phrase_renditions.
 *
 * Wypełnia renditcje LV dla wszystkich fraz kanonicznych. Po odpaleniu
 * `php artisan translations:auto-translate` composer skleja z nich nazwy LV
 * dla całego katalogu (i dla każdego nowego produktu z feedu Sumpguard).
 *
 * Forma nazwy (analog litewskiego „Plieninė variklio apsauga X"):
 *   „Tērauda dzinēja aizsargs Audi A4 B9"  /  „Alumīnija dzinēja aizsargs Mercedes V-Class"
 * Terminologia zgodna z rynkiem LV (dzinēja / ātrumkārbas / radiatora aizsargs).
 *
 * Zasady:
 *  - mapowanie po `slug` frazy (Str::slug(phrase_pl, '_')) — odporne na literówki w phrase_pl,
 *  - idempotentne: domyślnie NIE nadpisuje niepustych renditcji LV (--overwrite wymusza),
 *  - fraza spoza słownika = głośny raport, nie ciche pominięcie,
 *  - domyślnie dry-run; zapis dopiero z --apply.
 *
 * Użycie:
 *   php deploy/lv-matrix-seed.php                # dry-run
 *   php deploy/lv-matrix-seed.php --apply
 *   php deploy/lv-matrix-seed.php --apply --overwrite
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$opts = getopt('', ['apply', 'overwrite']);
$apply = isset($opts['apply']);
$overwrite = isset($opts['overwrite']);

/**
 * Fraza PL (klucz = phrase_pl z matrycy) → tłumaczenie łotewskie.
 *
 * Rdzeń „aizsargs" (osłona) stoi na KOŃCU — łotewski ma szyk head-final, więc określenia
 * (materiał w dopełniaczu + element w dopełniaczu) poprzedzają rdzeń:
 *   tērauda (stali) + dzinēja (silnika) + aizsargs (osłona)
 * Okoliczniki („ar Webasto", „Start-Stop sistēmai", „ar cinkojumu") zostają po rdzeniu.
 * Ten sam układ generuje PhraseRenditionDeriver dla przyszłych wariantów — wyniki są zgodne.
 */
$LV = [
    // --- bazowe: stal ---
    'Stalowa osłona silnika'                        => 'Tērauda dzinēja aizsargs',
    'Stalowa osłona skrzyni biegów'                 => 'Tērauda ātrumkārbas aizsargs',
    'Stalowa osłona zbiornika paliwa'               => 'Tērauda degvielas tvertnes aizsargs',
    'Stalowa osłona AdBlue'                         => 'Tērauda AdBlue tvertnes aizsargs',
    'Stalowa osłona dyferencjału'                   => 'Tērauda diferenciāļa aizsargs',
    'Stalowa osłona katalizatora'                   => 'Tērauda katalizatora aizsargs',
    'Stalowa osłona chłodnicy'                      => 'Tērauda radiatora aizsargs',
    'Stalowa osłona reduktora'                      => 'Tērauda reduktora aizsargs',
    'Stalowa osłona DPF'                            => 'Tērauda DPF filtra aizsargs',
    'Stalowa osłona EGR'                            => 'Tērauda EGR vārsta aizsargs',
    'Stalowa osłona przedniego zderzaka'            => 'Tērauda priekšējā bampera aizsargs',
    'Stalowa osłona akumulatora'                    => 'Tērauda akumulatora aizsargs',
    'Stalowa osłona skrzynki transferowej'          => 'Tērauda sadales kārbas aizsargs',

    // --- bazowe: aluminium (te bez odpowiednika stalowego w matrycy) ---
    'Aluminiowa osłona silnika'                     => 'Alumīnija dzinēja aizsargs',
    'Aluminiowa osłona skrzyni biegów'              => 'Alumīnija ātrumkārbas aizsargs',
    'Aluminiowa osłona zbiornika paliwa'            => 'Alumīnija degvielas tvertnes aizsargs',
    'Aluminiowa osłona dyferencjału'                => 'Alumīnija diferenciāļa aizsargs',
    'Aluminiowa osłona katalizatora'                => 'Alumīnija katalizatora aizsargs',
    'Aluminiowa osłona chłodnicy'                   => 'Alumīnija radiatora aizsargs',
    'Aluminiowa osłona reduktora'                   => 'Alumīnija reduktora aizsargs',
    'Aluminiowa osłona DPF'                         => 'Alumīnija DPF filtra aizsargs',
    'Aluminiowa osłona EGR'                         => 'Alumīnija EGR vārsta aizsargs',
    'Aluminiowa osłona filtra paliwa'               => 'Alumīnija degvielas filtra aizsargs',
    'Aluminiowa osłona skrzynki transferowej'       => 'Alumīnija sadales kārbas aizsargs',
    'Aluminiowa osłona czujnika tylnego wahacza'    => 'Alumīnija aizmugurējās sviras sensora aizsargs',

    // --- kombinacje elementów (sufiks nominalny wchodzi PRZED rdzeń) ---
    'Stalowa osłona silnika i skrzyni biegów'       => 'Tērauda dzinēja un ātrumkārbas aizsargs',
    'Stalowa osłona skrzyni biegów i reduktora'     => 'Tērauda ātrumkārbas un reduktora aizsargs',
    'Aluminiowa osłona skrzyni biegów i reduktora'  => 'Alumīnija ātrumkārbas un reduktora aizsargs',

    // --- modyfikatory (okoliczniki — po rdzeniu) ---
    'Stalowa osłona silnika galwanizowana'          => 'Tērauda dzinēja aizsargs ar cinkojumu',
    'Stalowa osłona silnika z Webasto'              => 'Tērauda dzinēja aizsargs ar Webasto',
    'Aluminiowa osłona silnika z Webasto'           => 'Alumīnija dzinēja aizsargs ar Webasto',
    'Stalowa osłona silnika System Start-Stop'      => 'Tērauda dzinēja aizsargs Start-Stop sistēmai',
    'Stalowa osłona EGR System Start-Stop'          => 'Tērauda EGR vārsta aizsargs Start-Stop sistēmai',
    'Stalowa osłona katalizatora System Start-Stop' => 'Tērauda katalizatora aizsargs Start-Stop sistēmai',
];

// Klucz roboczy = slug, bo phrase_pl w bazie bywa zapisane z innym wariantem wielkości liter.
$bySlug = [];
foreach ($LV as $pl => $lv) {
    $bySlug[Str::slug($pl, '_')] = ['pl' => $pl, 'lv' => $lv];
}

echo "Baza:  " . config('database.connections.' . config('database.default') . '.database') . "\n";
echo "Tryb:  " . ($apply ? 'ZAPIS (--apply)' : 'dry-run (bez zmian)') . ($overwrite ? ' + OVERWRITE' : '') . "\n";
echo "Słownik LV: " . count($bySlug) . " fraz\n\n";

$phrases = DB::table('translation_phrases')->orderByDesc('product_count')->get();
$existing = DB::table('translation_phrase_renditions')
    ->where('channel', 'lv')
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
    if ($current === $entry['lv']) {
        $stats['bez_zmian']++;
        continue;
    }
    if ($current !== '' && !$overwrite) {
        $stats['bez_zmian']++;
        continue; // już coś jest — nie ruszamy bez --overwrite
    }

    $current === '' ? $stats['dodane']++ : $stats['nadpisane']++;
    $doZapisu[] = ['id' => $phrase->id, 'value' => $entry['lv']];
    $zmiany[] = sprintf('  %-46s → %s', $phrase->phrase_pl, $entry['lv']);
}

echo "--- ANALIZA ---\n";
foreach ($stats as $k => $v) {
    echo '  ' . str_pad($k, 18) . $v . "\n";
}
echo '  ' . str_pad('DO ZAPISU', 18) . count($doZapisu) . "\n\n";

if ($zmiany) {
    echo "--- renditcje LV do zapisania ---\n" . implode("\n", $zmiany) . "\n\n";
}
if ($braki) {
    echo "!!! FRAZY BEZ TŁUMACZENIA LV (dopisz do słownika w tym pliku) !!!\n";
    echo implode("\n", $braki) . "\n\n";
}

// Frazy ze słownika, których nie ma w bazie — sygnał, że matryca lokalna ≠ produkcyjna.
$slugsWBazie = $phrases->pluck('slug')->all();
$nieuzyte = array_diff(array_keys($bySlug), $slugsWBazie);
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
            ['translation_phrase_id' => $row['id'], 'channel' => 'lv'],
            ['value' => $row['value'], 'source' => 'manual', 'updated_at' => now(), 'created_at' => now()]
        );
    }
});

$total = DB::table('translation_phrase_renditions')->where('channel', 'lv')->where('value', '<>', '')->count();
echo "\nGOTOWE. Zapisanych renditcji: " . count($doZapisu) . "\n";
echo "Renditcji LV w matrycy łącznie: {$total} / " . count($phrases) . " fraz\n";
echo "\nKolejny krok:  php artisan translations:auto-translate --dry-run\n";

exit($braki ? 1 : 0);
