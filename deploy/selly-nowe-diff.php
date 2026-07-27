<?php
/**
 * Nowości do wystawienia w oslonypareto.pl:
 *   świeży feed z proda (1616)  MINUS  eksport sklepu (po "Kod importu" = opis##{external_id})
 * Wynik: plik w formacie feedu 1:1 (te same 17 kolumn, ';' + BOM) — gotowy do importu „dodaj nowe".
 */
$dir    = __DIR__;
$feed   = $argv[2] ?? $dir . "/feed8_prod.csv";
$export = $argv[1] ?? 'C:/Users/Pareto 1/Downloads/1_export-product(5).csv';
$out    = $dir . '/_selly_nowe_2026-07-27.csv';

// --- 1. klucze ze sklepu ---
$fh = fopen($export, 'r');
$head = fgetcsv($fh, 0, ',');
$head[0] = trim(preg_replace('/^\xEF\xBB\xBF/', '', $head[0]), '"');   // BOM „zjada” cudzysłów pierwszego pola
$eIdx = array_flip($head);

if (!isset($eIdx['Kod importu'])) {
    fwrite(STDERR, "eksport: brak kolumny 'Kod importu'. Kolumny: " . implode(' | ', array_slice($head, 0, 12)) . "\n");
    exit(1);
}

$inShop = [];  $shopRows = 0; $emptyKey = 0;
while (($r = fgetcsv($fh, 0, ',')) !== false) {
    if (count($r) < 2) continue;
    $shopRows++;
    $k = trim($r[$eIdx['Kod importu']] ?? '');
    if ($k === '') { $emptyKey++; continue; }
    $inShop[$k] = true;
}
fclose($fh);

// --- 2. feed → wiersze, których sklep nie ma ---
$fh = fopen($feed, 'r');
$fHead = fgetcsv($fh, 0, ';');
$fHead[0] = trim(preg_replace("/^﻿/", "", $fHead[0]), "\"");
$fIdx = array_flip($fHead);

$new = []; $feedRows = 0; $noPrice = 0;
while (($r = fgetcsv($fh, 0, ';')) !== false) {
    if (count($r) < count($fHead)) continue;
    $feedRows++;
    $k = trim($r[$fIdx['Kod importu']]);
    if (isset($inShop[$k])) continue;

    $price = (float) str_replace(',', '.', $r[$fIdx['Cena brutto']]);
    if ($price <= 0) { $noPrice++; continue; }   // bez ceny nie wystawiamy
    $new[] = $r;
}
fclose($fh);

// --- 3. zapis w formacie feedu ---
$o = fopen($out, 'w');
fwrite($o, "\xEF\xBB\xBF");
fputcsv($o, $fHead, ';');
foreach ($new as $r) fputcsv($o, $r, ';');
fclose($o);

echo "eksport sklepu:      $shopRows wierszy (kluczy: " . count($inShop) . ", pustych: $emptyKey)\n";
echo "feed z proda:        $feedRows wierszy\n";
echo "NOWE (do dodania):   " . count($new) . "\n";
echo "pominiete bez ceny:  $noPrice\n";
echo "plik:                $out\n\n";

echo "--- probka 15 nowych ---\n";
foreach (array_slice($new, 0, 15) as $r) {
    printf("  %-16s %-14s %-52s %8s   kat=%s\n",
        $r[$fIdx['Kod importu']], $r[$fIdx['Kod producenta']],
        mb_substr($r[$fIdx['Nazwa produktu']], 0, 50),
        $r[$fIdx['Cena brutto']], $r[$fIdx['Kategoria ID']]);
}

// ile nowych trafia do kategorii-śmietnika 401 / bez mapy
$x = 0; foreach ($new as $r) { if (trim($r[$fIdx['Kategoria ID']]) === '401') $x++; }
echo "\nnowe lądujące w kategorii X (401): $x\n";
