<?php

/**
 * Zakłada / aktualizuje szablon opisu produktu dla rynku łotewskiego (templates.slug = 'bsp-lv').
 *
 * Treść szablonu trzymana jest w repo — `docs/lotwa/szablon_bsp_lv.blade.html` — żeby dało się ją
 * czytać i diffować normalnie, a nie tylko przez pole textarea w panelu. Skrypt wgrywa ten plik
 * do bazy, więc plik jest źródłem prawdy, a baza kopią roboczą.
 *
 * Odpowiednik litewskiego 'bsp-lt' (id 14 na prodzie) z jedną poprawką: rozpoznanie aluminium
 * NIE porównuje się do polskiego napisu 'Aluminium'. Pipeline renderuje szablon w locale szablonu
 * (`app()->setLocale($source->template->locale)`), więc `$attribute_material` przychodzi już po
 * łotewsku ('Alumīnijs'). Warunek `str_starts_with(..., 'alum')` łapie oba warianty.
 *
 * Użycie:
 *   php deploy/lv-template-seed.php              # dry-run
 *   php deploy/lv-template-seed.php --apply
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$opts = getopt('', ['apply']);
$apply = isset($opts['apply']);

const SLUG = 'bsp-lv';
const LOCALE = 'lv';

$descriptionFile = __DIR__ . '/../docs/lotwa/szablon_bsp_lv.blade.html';
if (!is_readable($descriptionFile)) {
    fwrite(STDERR, "Brak pliku z treścią szablonu: {$descriptionFile}\n");
    exit(1);
}

$title = '{{ $name }} ({{ $attribute_year_start }}-{{ $attribute_year_stop }})';

$payload = [
    'slug'             => SLUG,
    'locale'           => LOCALE,
    'name'             => 'BSP - LV',
    'title'            => $title,
    'meta_title'       => $title,
    'meta_description' => $title,
    'description'      => rtrim(file_get_contents($descriptionFile), "\r\n"),
    // short_description celowo puste — tak samo jak w bsp-lt; OpenCart i tak ignoruje to pole.
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

// Kontrola: czy szablon w ogóle się renderuje (Blade musi się skompilować, zanim trafi do eksportu).
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
    DB::table('templates')->where('id', $existing->id)
        ->update($payload + ['updated_at' => now()]);
    echo "ZAKTUALIZOWANO szablon id={$existing->id}.\n";
} else {
    $id = DB::table('templates')->insertGetId($payload + ['created_at' => now(), 'updated_at' => now()]);
    echo "UTWORZONO szablon id={$id}.\n";
}

echo "\nSzablon nie jest jeszcze podpięty do żadnej integracji — zrób to w Argo Connect →\n";
echo "Integracje → [integracja LV] → źródło → pole 'Szablon', albo ustaw integration_sources.template_id.\n";
