<?php

/**
 * Zrzut stanu przed wdrożeniem locale (LV/ET) — do rollbacku.
 *
 * Zrzuca dokładnie te tabele, które ruszają seedy i `translations:auto-translate`:
 *   products.name, translation_phrase_renditions, translation_overrides, templates
 *
 * Najważniejszy jest `products_name.json`: composer prostuje przy okazji nazwy POLSKIE
 * (lock `auto_matrix` nie chroni PL — chronią tylko `manual`/`sheet_import`), a na produkcji
 * 1596 produktów ma właśnie taki lock. Bez tego zrzutu nie da się udowodnić, że PL ocalało.
 *
 * Użycie:
 *   php deploy/lv-et-backup.php                 # katalog z datą w ~/
 *   php deploy/lv-et-backup.php --dir=/sciezka
 *
 * Weryfikacja po wdrożeniu i rollback — patrz docs/lotwa/WDROZENIE.md
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$opts = getopt('', ['dir::']);
$dir = $opts['dir'] ?? (($_SERVER['HOME'] ?? sys_get_temp_dir()) . '/backup-pim-lv-et-' . date('Ymd-His'));

if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
    fwrite(STDERR, "Nie moge utworzyc katalogu: {$dir}\n");
    exit(1);
}

echo "Baza:    " . config('database.connections.' . config('database.default') . '.database') . "\n";
echo "Katalog: {$dir}\n\n";

$zrzuty = [
    // products: tylko id + name — reszta kolumn nie jest ruszana, a plik ma być mały
    'products_name' => fn () => DB::table('products')->pluck('name', 'id'),
    'renditions'    => fn () => DB::table('translation_phrase_renditions')->get(),
    'overrides'     => fn () => DB::table('translation_overrides')->get(),
    'templates'     => fn () => DB::table('templates')->get(),
];

$ok = true;
foreach ($zrzuty as $nazwa => $zapytanie) {
    $dane = $zapytanie();
    $plik = "{$dir}/{$nazwa}.json";
    $json = json_encode($dane, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || file_put_contents($plik, $json) === false) {
        fwrite(STDERR, "BLAD zapisu: {$plik}\n");
        $ok = false;
        continue;
    }
    printf("  %-16s %6d rekordow, %8d B\n", $nazwa, count($dane), filesize($plik));
}

if (!$ok) {
    fwrite(STDERR, "\nZrzut NIEKOMPLETNY — nie wdrazaj.\n");
    exit(1);
}

// Kontrola odczytu: zrzut bez możliwości wczytania jest bezwartościowy.
foreach (array_keys($zrzuty) as $nazwa) {
    if (json_decode(file_get_contents("{$dir}/{$nazwa}.json"), true) === null) {
        fwrite(STDERR, "BLAD: {$nazwa}.json nie daje sie sparsowac — nie wdrazaj.\n");
        exit(1);
    }
}

echo "\nZrzut kompletny i czytelny.\n";
echo "Do weryfikacji po wdrozeniu podaj ten katalog:\n  {$dir}\n";
