<?php
/**
 * Dodaje atrybut "Materiał" (slug: material) z wartościami Stal / Aluminium
 * (tłumaczenia w 14 locale) i przypina go do WSZYSTKICH produktów:
 *   - ALU  = product_code zawiera "alu" (case-insensitive) LUB nazwa PL zawiera "alumin"
 *   - STAL = cała reszta
 *
 * Bezpieczeństwo:
 *   - NIE dotyka products.name ani żadnych tłumaczeń produktów — tylko tabele
 *     attributes / attribute_values / attribute_value_product (pivot).
 *   - Idempotentny: drugi run = 0 zmian. Rusza w pivocie WYŁĄCZNIE wiersze
 *     wskazujące na wartości stal/aluminium — inne atrybuty nietknięte.
 *
 * Uruchomienie:  php _add_material_attribute.php          (local)
 *                /usr/local/php83/bin/php _add_material_attribute.php   (prod)
 */

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Attribute;
use App\Models\AttributeValue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

// Masowa operacja automatyczna — nie flaguj slotów tłumaczeń jako 'manual'
// (tak samo robią importy i SumpguardSource). Na prodzie (stary kod, bez tej
// klasy) class_exists chroni przed fatalem.
if (class_exists(\App\Models\TranslationOverride::class)) {
    \App\Models\TranslationOverride::$suppressObserver = true;
}

$attributeName = [
    'pl' => 'Materiał', 'en' => 'Material', 'de' => 'Material', 'fr' => 'Matériau',
    'cs' => 'Materiál', 'sk' => 'Materiál', 'lt' => 'Medžiaga', 'es' => 'Material',
    'it' => 'Materiale', 'lv' => 'Materiāls', 'et' => 'Materjal', 'ro' => 'Material',
    'hu' => 'Anyag', 'bg' => 'Материал',
];

$steelName = [
    'pl' => 'Stal', 'en' => 'Steel', 'de' => 'Stahl', 'fr' => 'Acier',
    'cs' => 'Ocel', 'sk' => 'Oceľ', 'lt' => 'Plienas', 'es' => 'Acero',
    'it' => 'Acciaio', 'lv' => 'Tērauds', 'et' => 'Teras', 'ro' => 'Oțel',
    'hu' => 'Acél', 'bg' => 'Стомана',
];

$aluName = [
    'pl' => 'Aluminium', 'en' => 'Aluminium', 'de' => 'Aluminium', 'fr' => 'Aluminium',
    'cs' => 'Hliník', 'sk' => 'Hliník', 'lt' => 'Aliuminis', 'es' => 'Aluminio',
    'it' => 'Alluminio', 'lv' => 'Alumīnijs', 'et' => 'Alumiinium', 'ro' => 'Aluminiu',
    'hu' => 'Alumínium', 'bg' => 'Алуминий',
];

// ── 1. Atrybut "Materiał" ────────────────────────────────────────────────────
$attribute = Attribute::where('slug', 'material')->first();
if (!$attribute) {
    $attribute = new Attribute(['slug' => 'material', 'order' => (int) Attribute::max('order') + 1]);
}
$attribute->setTranslations('name', $attributeName);
$attribute->save();
echo "Atrybut: id={$attribute->id} slug={$attribute->slug}\n";

// ── 2. Wartości Stal / Aluminium ─────────────────────────────────────────────
$makeValue = function (string $slug, array $names) use ($attribute): AttributeValue {
    $value = AttributeValue::where('attribute_id', $attribute->id)->where('slug', $slug)->first();
    if (!$value) {
        $value = new AttributeValue(['attribute_id' => $attribute->id, 'slug' => $slug]);
    }
    $value->setTranslations('name', $names);
    $value->save();
    return $value;
};

$steel = $makeValue('stal', $steelName);
$alu   = $makeValue('aluminium', $aluName);
echo "Wartości: stal id={$steel->id}, aluminium id={$alu->id}\n";

// ── 3. Klasyfikacja produktów ────────────────────────────────────────────────
$products = DB::table('products')->select('id', 'product_code', 'name')->get();

$targets = [];       // product_id => attribute_value_id
$countAlu = $countSteel = 0;
$mismatches = [];    // kod mówi co innego niż nazwa PL — do ręcznego przejrzenia

foreach ($products as $p) {
    $namePl  = (string) (json_decode($p->name ?? '{}', true)['pl'] ?? '');
    $byCode  = (bool) preg_match('/alu/i', (string) $p->product_code);
    $byName  = mb_stripos($namePl, 'alumin') !== false;

    if ($byCode !== $byName && $namePl !== '') {
        $mismatches[] = "id={$p->id} kod={$p->product_code} nazwa={$namePl}";
    }

    $isAlu = $byCode || $byName;
    $targets[$p->id] = $isAlu ? $alu->id : $steel->id;
    $isAlu ? $countAlu++ : $countSteel++;
}

echo "Produkty: " . count($targets) . " (aluminium: {$countAlu}, stal: {$countSteel})\n";

// ── 4. Pivot: dopnij właściwą wartość, zdejmij przeciwną (tylko te 2 wartości!)
$existing = DB::table('attribute_value_product')
    ->whereIn('attribute_value_id', [$steel->id, $alu->id])
    ->get(['product_id', 'attribute_value_id'])
    ->groupBy('product_id');

$now = date('Y-m-d H:i:s');
$inserts = [];
$deleted = 0;

foreach ($targets as $productId => $targetValueId) {
    $current = collect($existing->get($productId, collect()))->pluck('attribute_value_id');

    $wrong = $current->unique()->reject(fn ($id) => (int) $id === (int) $targetValueId);
    if ($wrong->isNotEmpty()) {
        $deleted += DB::table('attribute_value_product')
            ->where('product_id', $productId)
            ->whereIn('attribute_value_id', $wrong->all())
            ->delete();
    }

    if (!$current->contains(fn ($id) => (int) $id === (int) $targetValueId)) {
        $inserts[] = [
            'attribute_value_id' => $targetValueId,
            'product_id'         => $productId,
            'created_at'         => $now,
            'updated_at'         => $now,
        ];
    }
}

foreach (array_chunk($inserts, 500) as $chunk) {
    DB::table('attribute_value_product')->insert($chunk);
}

echo "Pivot: dodano " . count($inserts) . ", usunięto błędnych {$deleted}\n";

// ── 5. Cache zmiennych szablonów (getVariables cache'uje listę atrybutów 1h) ─
Cache::forget('attributes');
echo "Cache 'attributes' wyczyszczony.\n";

// ── 6. Diagnostyka ───────────────────────────────────────────────────────────
if ($mismatches) {
    echo "\n⚠ Rozjazdy kod vs nazwa PL (" . count($mismatches) . ") — sprawdź ręcznie:\n";
    foreach ($mismatches as $m) {
        echo "  {$m}\n";
    }
} else {
    echo "Rozjazdów kod vs nazwa PL: 0\n";
}

$aluNoWidth = DB::table('products')
    ->whereIn('id', array_keys(array_filter($targets, fn ($v) => $v === $alu->id)))
    ->where(fn ($q) => $q->whereNull('width')->orWhere('width', 0))
    ->count();
echo "Produkty ALU z width=0/null (szablon pominie grubość): {$aluNoWidth}\n";

$check = DB::table('attribute_value_product')
    ->whereIn('attribute_value_id', [$steel->id, $alu->id])
    ->selectRaw('attribute_value_id, COUNT(*) c')
    ->groupBy('attribute_value_id')
    ->pluck('c', 'attribute_value_id');
echo "Kontrola w bazie: stal=" . ($check[$steel->id] ?? 0) . ", aluminium=" . ($check[$alu->id] ?? 0) . "\n";
echo "GOTOWE.\n";
