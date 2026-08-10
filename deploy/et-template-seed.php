<?php

/**
 * Zakłada / aktualizuje szablon opisu produktu dla rynku estońskiego (templates.slug = 'bsp-et').
 *
 * Bliźniak `lv-template-seed.php`. Treść w repo: `docs/estonia/szablon_bsp_et.blade.html`
 * (plik jest źródłem prawdy, baza kopią roboczą).
 *
 * Rozpoznanie aluminium nie porównuje się do polskiego napisu 'Aluminium' — pipeline renderuje
 * szablon w locale szablonu, więc `$attribute_material` przychodzi już jako 'Alumiinium'.
 *
 * Użycie:
 *   php deploy/et-template-seed.php              # dry-run
 *   php deploy/et-template-seed.php --apply
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$opts = getopt('', ['apply']);
$apply = isset($opts['apply']);

const SLUG = 'bsp-et';
const LOCALE = 'et';

$descriptionFile = __DIR__ . '/../docs/estonia/szablon_bsp_et.blade.html';
if (!is_readable($descriptionFile)) {
    fwrite(STDERR, "Brak pliku z treścią szablonu: {$descriptionFile}\n");
    exit(1);
}

$title = '{{ $name }} ({{ $attribute_year_start }}-{{ $attribute_year_stop }})';

$payload = [
    'slug'              => SLUG,
    'locale'            => LOCALE,
    'name'              => 'BSP - ET',
    'title'             => $title,
    'meta_title'        => $title,
    'meta_description'  => $title,
    'description'       => rtrim(file_get_contents($descriptionFile), "\r\n"),
    'short_description' => null,
];

echo "Baza:  " . config('database.connections.' . config('database.default') . '.database') . "\n";
echo "Tryb:  " . ($apply ? 'ZAPIS (--apply)' : 'dry-run (bez zmian)') . "\n";
echo "Plik:  {$descriptionFile} (" . strlen($payload['description']) . " B)\n\n";

$existing = DB::table('templates')->where('slug', SLUG)->first();

if ($existing) {
    echo "Szablon '" . SLUG . "' ISTNIEJE (id={$existing->id}, locale={$existing->locale}).\n";
    $diff = [];
    foreach ($payload as $col => $val) {
        $old = $existing->{$col} ?? null;
        if ((string) $old !== (string) $val) {
            $diff[] = $col === 'description'
                ? sprintf('  description: %d B → %d B', strlen((string) $old), strlen((string) $val))
                : sprintf('  %s: %s → %s', $col, var_export($old, true), var_export($val, true));
        }
    }
    if (!$diff) {
        echo "Bez zmian — treść w bazie jest identyczna z plikiem.\n";
        exit(0);
    }
    echo "Do aktualizacji:\n" . implode("\n", $diff) . "\n\n";
} else {
    echo "Szablon '" . SLUG . "' NIE ISTNIEJE — zostanie utworzony.\n\n";
}

$product = App\Models\Product::with('attributeValues.attribute')->whereNotNull('product_code')->first();
if ($product) {
    $poprzedni = app()->getLocale();
    app()->setLocale(LOCALE);
    try {
        $tpl = new App\Models\Template($payload);
        $tpl->locale = LOCALE;
        $render = $tpl->getRenderedDescription($product);
        echo "Render testowy (produkt #{$product->id}): OK, " . strlen($render) . " B\n\n";
    } catch (\Throwable $e) {
        fwrite(STDERR, "BŁĄD RENDEROWANIA SZABLONU: " . $e->getMessage() . "\n");
        exit(1);
    } finally {
        app()->setLocale($poprzedni);
    }
}

if (!$apply) {
    echo "DRY-RUN — nic nie zapisano. Dodaj --apply, żeby zapisać.\n";
    exit(0);
}

if ($existing) {
    DB::table('templates')->where('id', $existing->id)->update($payload + ['updated_at' => now()]);
    echo "ZAKTUALIZOWANO szablon id={$existing->id}.\n";
} else {
    $id = DB::table('templates')->insertGetId($payload + ['created_at' => now(), 'updated_at' => now()]);
    echo "UTWORZONO szablon id={$id}.\n";
}

echo "\nSzablon nie jest jeszcze podpięty do żadnej integracji — zrób to w Argo Connect →\n";
echo "Integracje → [integracja ET] → źródło → pole 'Szablon', albo ustaw integration_sources.template_id.\n";
