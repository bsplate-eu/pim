<?php
/**
 * NAPRAWA DE: lock 'auto_matrix' na slocie name/de zamroził starą POLSKĄ nazwę
 * (composer skipuje auto_matrix dla de/cs/sk/fr/es, ale NIE dla pl — stąd PL
 * naprawiony, DE nie; diagnoza 2026-07-13/14, ~122 produkty na prodzie).
 *
 * Co robi: dla produktów z lockiem (field=name, locale=de, source=auto_matrix)
 * liczy compose()['channels']['de'] i wpisuje do products.name->de.
 * Lock zostaje (wartość jest teraz poprawna, z matrycy).
 *
 * Bezpieczeństwo:
 *   - domyślnie DRY-RUN (nic nie zapisuje) — pokazuje PRZED → PO
 *   - zapis tylko z flagą --apply
 *   - pomija: brak dopasowania matrycy (channels.de=null), wartość już poprawna
 *   - observer stłumiony (zapis NIE tworzy nowego locka 'manual')
 *
 * Zakres: domyślnie TYLKO sloty de wyglądające po polsku (osłon/silnik/skrzyn/…)
 * = dokładnie przypadki buga. Flaga --all rozszerza na wszystkie rozjazdy vs matryca
 * (też kosmetyczne, np. brak ogona 4x4) — używać świadomie.
 *
 * Uruchom w roocie PIM:
 *   /usr/local/php83/bin/php _fix_de.php            (podgląd)
 *   /usr/local/php83/bin/php _fix_de.php --apply    (zapis)
 */
ini_set('memory_limit', '1024M');
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Product;
use App\Models\TranslationOverride;
use App\Services\ProductTranslationComposer;

$apply = in_array('--apply', $argv, true);
$all   = in_array('--all', $argv, true);
echo $apply ? "=== TRYB APPLY — zapisuję ===\n" : "=== DRY-RUN (podgląd; zapis: --apply) ===\n";
echo $all ? "Zakres: WSZYSTKIE rozjazdy vs matryca (--all)\n" : "Zakres: tylko sloty de wyglądające po polsku (bug); pełny zakres: --all\n";

// Polskie markery w nazwie = zamrożona stara PL na slocie de (sedno buga)
$looksPolish = fn (string $s): bool =>
    (bool) preg_match('/osłon|oslon|silnik|skrzyn|biegów|biegow|chłodnic|chlodnic|zderzak|Stalowa|Aluminiowa/iu', $s);

$composer = app(ProductTranslationComposer::class);

// Produkty z lockiem auto_matrix na slocie name/de
$lockedIds = TranslationOverride::query()
    ->where('field', 'name')
    ->where('locale', 'de')
    ->where('source', TranslationOverride::SOURCE_AUTO_MATRIX)
    ->whereIn('translatable_type', [Product::class, 'App\\Models\\Product', 'product'])
    ->pluck('translatable_id')
    ->unique()
    ->values();

echo "Produktów z lockiem auto_matrix na name/de: {$lockedIds->count()}\n\n";

$fixed = $same = $unmatched = $outOfScope = 0;
TranslationOverride::$suppressObserver = true;

foreach (Product::whereIn('id', $lockedIds)->cursor() as $p) {
    $before = (string) $p->getTranslation('name', 'de', false);
    if (!$all && !$looksPolish($before)) {
        $outOfScope++;
        continue; // niemiecko wyglądający slot — poza zakresem buga
    }
    $composed = $composer->compose($p);
    $target = $composed['channels']['de'] ?? null;

    if ($target === null || trim((string) $target) === '') {
        $unmatched++;
        echo "SKIP (matryca nie rozpoznaje) #{$p->id} {$p->product_code}: [de] {$before}\n";
        continue;
    }
    if ($before === $target) {
        $same++;
        continue;
    }

    $fixed++;
    echo "FIX #{$p->id} {$p->product_code}\n  PRZED: {$before}\n  PO:    {$target}\n";
    if ($apply) {
        $p->setTranslation('name', 'de', $target);
        $p->saveQuietly(); // bez eventów — nie ruszamy syncu/observerów
    }
}

TranslationOverride::$suppressObserver = false;

echo "\n=== PODSUMOWANIE ===\n";
echo "do naprawy: {$fixed}" . ($apply ? " (ZAPISANO)" : " (dry-run, NIE zapisano)") . "\n";
echo "już poprawne: {$same}\n";
echo "nierozpoznane przez matrycę (nietknięte): {$unmatched}\n";
echo "poza zakresem (de wygląda OK; obejmiesz przez --all): {$outOfScope}\n";
if (!$apply && $fixed > 0) echo "\nOdpal ponownie z --apply żeby zapisać.\n";
