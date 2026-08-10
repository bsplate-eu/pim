<?php

/**
 * Weryfikacja po wdrożeniu locale (LV/ET) — porównuje aktualny stan bazy ze zrzutem
 * zrobionym przez `lv-et-backup.php`.
 *
 * Najważniejsza kontrola: czy `translations:auto-translate` nie ruszył nazw POLSKICH.
 * Composer prostuje PL przy okazji (lock `auto_matrix` go nie blokuje), a na produkcji
 * ma taki lock 1596 produktów. Oczekiwany wynik to ZERO zmian w PL — każda zmiana
 * jest tu wypisana z osobna, do decyzji człowieka.
 *
 * Kod wyjścia: 0 = czysto, 1 = są zmiany w PL albo braki w pokryciu.
 *
 * Użycie:
 *   php deploy/lv-et-verify.php --dir=/home/admin/backup-pim-lv-et-20260810-094500
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$opts = getopt('', ['dir:', 'pokaz-zmiany::']);
$dir = $opts['dir'] ?? null;
$limitZmian = (int) ($opts['pokaz-zmiany'] ?? 20);

if (!$dir || !is_readable("{$dir}/products_name.json")) {
    fwrite(STDERR, "Podaj katalog zrzutu: --dir=/home/admin/backup-pim-lv-et-...\n");
    exit(1);
}

$stare = json_decode(file_get_contents("{$dir}/products_name.json"), true);
if (!is_array($stare)) {
    fwrite(STDERR, "Nie moge odczytac zrzutu products_name.json\n");
    exit(1);
}

echo "Baza:   " . config('database.connections.' . config('database.default') . '.database') . "\n";
echo "Zrzut:  {$dir} (" . count($stare) . " produktow)\n\n";

$locale = ['pl', 'lv', 'et'];
$zmiany = array_fill_keys($locale, 0);
$puste  = array_fill_keys($locale, 0);
$przyklady = [];
$nowe = 0;

foreach (DB::table('products')->get(['id', 'name']) as $p) {
    $poJson = json_decode((string) $p->name, true) ?: [];
    $przedRaw = $stare[(string) $p->id] ?? $stare[$p->id] ?? null;
    if ($przedRaw === null) {
        $nowe++;   // produkt dodany po zrzucie — nie ma z czym porównywać
        continue;
    }
    $przedJson = json_decode((string) $przedRaw, true) ?: [];

    foreach ($locale as $l) {
        $a = trim((string) ($przedJson[$l] ?? ''));
        $b = trim((string) ($poJson[$l] ?? ''));
        if ($b === '') {
            $puste[$l]++;
        }
        if ($a !== $b) {
            $zmiany[$l]++;
            if ($l === 'pl' && count($przyklady) < $limitZmian) {
                $przyklady[] = "  id={$p->id}\n    BYLO: {$a}\n    JEST: {$b}";
            }
        }
    }
}

$total = DB::table('products')->count();

echo "--- ZMIANY WZGLEDEM ZRZUTU ---\n";
printf("  pl: %5d zmian   %s\n", $zmiany['pl'], $zmiany['pl'] === 0 ? '(OK — oczekiwane zero)' : '<<< UWAGA');
printf("  lv: %5d zmian   (oczekiwane: wypelnienie katalogu)\n", $zmiany['lv']);
printf("  et: %5d zmian   (oczekiwane: wypelnienie katalogu)\n", $zmiany['et']);
if ($nowe) {
    printf("  (%d produktow spoza zrzutu — pominiete w porownaniu)\n", $nowe);
}

echo "\n--- POKRYCIE (stan obecny) ---\n";
foreach ($locale as $l) {
    printf("  %s: %d / %d wypelnionych\n", $l, $total - $puste[$l], $total);
}

if ($przyklady) {
    echo "\n!!! NAZWY POLSKIE ZMIENIONE — sprawdz, czy to poprawa czy regresja !!!\n";
    echo implode("\n", $przyklady) . "\n";
    if ($zmiany['pl'] > count($przyklady)) {
        echo "  ... i " . ($zmiany['pl'] - count($przyklady)) . " wiecej\n";
    }
}

// Pokrycie matrycy i szablony — druga połowa wdrożenia
echo "\n--- MATRYCA ---\n";
$frazy = DB::table('translation_phrases')->count();
foreach (['lv', 'et'] as $kanal) {
    $n = DB::table('translation_phrase_renditions')
        ->where('channel', $kanal)->where('value', '<>', '')->count();
    printf("  renditcje %s: %d / %d fraz%s\n", $kanal, $n, $frazy, $n === $frazy ? '' : '   <<< BRAKI');
}
echo "\n--- SZABLONY ---\n";
foreach (['bsp-lv', 'bsp-et'] as $slug) {
    $t = DB::table('templates')->where('slug', $slug)->first();
    printf("  %-8s %s\n", $slug, $t ? "id={$t->id}, locale={$t->locale}, opis " . strlen((string) $t->description) . " B" : 'BRAK   <<<');
}

$zleZePl = $zmiany['pl'] > 0;
$brakiMatrycy = DB::table('translation_phrase_renditions')->whereIn('channel', ['lv', 'et'])
        ->where('value', '<>', '')->count() < 2 * $frazy;

echo "\n" . (($zleZePl || $brakiMatrycy) ? "WYNIK: wymaga uwagi (patrz wyzej)\n" : "WYNIK: czysto — PL nietkniete, matryca kompletna\n");
exit(($zleZePl || $brakiMatrycy) ? 1 : 0);
