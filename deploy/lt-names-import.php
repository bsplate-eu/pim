<?php

/**
 * Import litewskich nazw produktow (products.name -> slot "lt") z pliku CSV.
 *
 * CSV: id,external_id,name,overrides_name
 *      id             = products.id
 *      name           = nazwa PL (kontrola zgodnosci, NIE jest zapisywana)
 *      overrides_name = nazwa LT do zapisania
 *
 * Zasady (po incydencie na nazwach z 2026-07-02):
 *  - merge PER SLOT: podmieniamy wylacznie klucz "lt", reszta JSON-a nietknieta,
 *  - kontrola zgodnosci PL: wiersz, ktorego "name" nie zgadza sie z baza, jest POMIJANY,
 *  - domyslnie dry-run; zapis dopiero z --apply,
 *  - po zapisie zakladamy lock w translation_overrides (locale=lt, field=name),
 *    zeby feed Sumpguard i repair-encoding nie skasowaly tlumaczen.
 *
 * Uzycie:
 *   php deploy/lt-names-import.php --file=/sciezka/LT.csv            # dry-run
 *   php deploy/lt-names-import.php --file=/sciezka/LT.csv --apply
 *   php deploy/lt-names-import.php --file=... --apply --force-pl     # ignoruj niezgodnosc PL
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$opts = getopt('', ['file:', 'apply', 'force-pl', 'limit::']);
$file = $opts['file'] ?? null;
$apply = isset($opts['apply']);
$forcePl = isset($opts['force-pl']);
$limit = (int) ($opts['limit'] ?? 0);

if (!$file || !is_readable($file)) {
    fwrite(STDERR, "Podaj czytelny plik: --file=/sciezka/LT.csv\n");
    exit(1);
}

// ---------- wczytanie CSV ----------
$content = file_get_contents($file);
if (str_starts_with($content, "\xEF\xBB\xBF")) {
    $content = substr($content, 3);
}
$lines = preg_split("/\r\n|\n|\r/", $content) ?: [];
$header = null;
$rows = [];
foreach ($lines as $line) {
    if ($line === '') {
        continue;
    }
    $parsed = str_getcsv($line, ',');
    if ($header === null) {
        $header = array_map('trim', $parsed);
        continue;
    }
    $rows[] = array_combine($header, array_pad(array_slice($parsed, 0, count($header)), count($header), ''));
}

foreach (['id', 'name', 'overrides_name'] as $col) {
    if (!in_array($col, $header, true)) {
        fwrite(STDERR, "CSV nie ma kolumny \"$col\"\n");
        exit(1);
    }
}

echo "Plik:        $file\n";
echo "Wierszy CSV: " . count($rows) . "\n";
echo "Tryb:        " . ($apply ? "ZAPIS (--apply)" : "dry-run (bez zmian)") . "\n";
echo "Baza:        " . config('database.connections.' . config('database.default') . '.database') . "\n\n";

// ---------- porownanie z baza ----------
$stats = [
    'brak_w_bazie' => 0,
    'pl_niezgodne' => 0,
    'puste_lt' => 0,
    'bez_zmian' => 0,
    'nadpisane' => 0,   // slot lt mial wczesniej inna wartosc
    'dodane' => 0,      // slot lt byl pusty
];
$doZapisu = [];
$przyklady = ['pl_niezgodne' => [], 'nadpisane' => []];

$ids = array_values(array_unique(array_map(fn($r) => (int) $r['id'], $rows)));
$db = [];
foreach (array_chunk($ids, 500) as $chunk) {
    foreach (DB::table('products')->select('id', 'name')->whereIn('id', $chunk)->get() as $p) {
        $db[(int) $p->id] = (string) $p->name;
    }
}

$i = 0;
foreach ($rows as $r) {
    if ($limit && $i >= $limit) {
        break;
    }
    $i++;

    $id = (int) $r['id'];
    $plCsv = trim((string) $r['name']);
    $ltCsv = trim((string) $r['overrides_name']);

    if ($ltCsv === '') {
        $stats['puste_lt']++;
        continue;
    }
    if (!isset($db[$id])) {
        $stats['brak_w_bazie']++;
        continue;
    }

    $json = json_decode($db[$id], true);
    if (!is_array($json)) {
        // nazwa nie jest JSON-em (pojedynczy string) — nie ruszamy, za duze ryzyko
        $stats['brak_w_bazie']++;
        continue;
    }

    $plDb = trim((string) ($json['pl'] ?? ''));
    if ($plDb !== $plCsv) {
        $stats['pl_niezgodne']++;
        if (count($przyklady['pl_niezgodne']) < 5) {
            $przyklady['pl_niezgodne'][] = "  id=$id\n    CSV: $plCsv\n    BAZA: $plDb";
        }
        if (!$forcePl) {
            continue;
        }
    }

    $ltDb = trim((string) ($json['lt'] ?? ''));
    if ($ltDb === $ltCsv) {
        $stats['bez_zmian']++;
        continue;
    }

    if ($ltDb === '') {
        $stats['dodane']++;
    } else {
        $stats['nadpisane']++;
        if (count($przyklady['nadpisane']) < 5) {
            $przyklady['nadpisane'][] = "  id=$id\n    BYLO: $ltDb\n    BEDZIE: $ltCsv";
        }
    }

    $json['lt'] = $ltCsv;                       // MERGE PER SLOT — reszta locale nietknieta
    $doZapisu[$id] = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

// ---------- raport ----------
echo "--- ANALIZA ---\n";
foreach ($stats as $k => $v) {
    echo '  ' . str_pad($k, 16) . $v . "\n";
}
echo '  ' . str_pad('DO ZAPISU', 16) . count($doZapisu) . "\n\n";

if ($przyklady['pl_niezgodne']) {
    echo "--- niezgodnosc PL (pominiete" . ($forcePl ? ', ale --force-pl => i tak zapisze' : '') . ") ---\n";
    echo implode("\n", $przyklady['pl_niezgodne']) . "\n\n";
}
if ($przyklady['nadpisane']) {
    echo "--- nadpisywane sloty lt (probka) ---\n";
    echo implode("\n", $przyklady['nadpisane']) . "\n\n";
}

if (!$apply) {
    echo "DRY-RUN — nic nie zapisano. Dodaj --apply, zeby zapisac.\n";
    exit(0);
}
if (!$doZapisu) {
    echo "Nic do zapisania.\n";
    exit(0);
}

// ---------- zapis ----------
// Observer ustawia lock 'manual' przy zapisie modelu; piszemy zapytaniem, wiec lock
// zakladamy sami jako 'sheet_import' (import z pliku, wartosc blokujaca).
$zapisane = 0;
foreach (array_chunk($doZapisu, 200, true) as $chunk) {
    DB::transaction(function () use ($chunk, &$zapisane) {
        foreach ($chunk as $id => $nameJson) {
            DB::table('products')->where('id', $id)->update(['name' => $nameJson]);
            DB::table('translation_overrides')->updateOrInsert(
                [
                    'translatable_type' => 'App\\Models\\Product',
                    'translatable_id' => $id,
                    'field' => 'name',
                    'locale' => 'lt',
                ],
                [
                    'source' => 'sheet_import',
                    'locked_at' => now(),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
            $zapisane++;
        }
    });
    echo "  zapisano: $zapisane\n";
}

echo "\nGOTOWE. Zapisanych produktow: $zapisane\n";
echo "Locki lt w translation_overrides: " . DB::table('translation_overrides')
        ->where('field', 'name')->where('locale', 'lt')->count() . "\n";
