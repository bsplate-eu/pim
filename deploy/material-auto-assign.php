<?php
/**
 * Auto-oznaczenie atrybutu „Materiał" wg KODU PRODUKTU.
 *
 *   product_code zawiera "ALU"  →  Aluminium
 *   pozostałe                   →  Stal
 *
 * Najpierw DIAGNOZA (co jest w bazie), potem przypisanie. Domyślnie dry-run.
 *
 *   php deploy/material-auto-assign.php            # tylko pokazuje co zrobi
 *   php deploy/material-auto-assign.php --apply    # zapisuje
 *
 * Bezpieczeństwo:
 *   - NIE dotyka products.name ani tłumaczeń; rusza wyłącznie pivot
 *     attribute_value_product i tylko wiersze wskazujące na wartości
 *     stal/aluminium TEGO atrybutu. Inne atrybuty produktu nietknięte.
 *   - Idempotentny: drugi przebieg = 0 zmian.
 *   - Gdy atrybut „Materiał" istnieje pod innym slugiem (Sluggable dokleja
 *     -1/-2 przy kolizji), skrypt go ZNAJDUJE i używa zamiast tworzyć duplikat.
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Attribute;
use App\Models\AttributeValue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

$apply = in_array('--apply', $argv, true);

// Masowa operacja automatyczna — nie flaguj slotów tłumaczeń jako 'manual'.
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

echo $apply ? "== TRYB: APPLY (zapis) ==\n\n" : "== TRYB: DRY-RUN (bez zapisu; dodaj --apply) ==\n\n";

// ── DIAGNOZA ─────────────────────────────────────────────────────────────────
echo "--- DIAGNOZA: atrybuty wygladajace na Material ---\n";
$candidates = Attribute::with('values')->get()->filter(function ($a) {
    if (str_starts_with((string) $a->slug, 'material')) return true;
    foreach (['pl', 'en', 'de'] as $loc) {
        if (mb_stripos((string) $a->getTranslation('name', $loc, false), 'materia') !== false) return true;
    }
    return false;
});

if ($candidates->isEmpty()) {
    echo "  (brak — zostanie utworzony)\n";
}
foreach ($candidates as $a) {
    $ids = $a->values->pluck('id')->all();
    $used = $ids ? DB::table('attribute_value_product')->whereIn('attribute_value_id', $ids)->count() : 0;
    echo "  id={$a->id} slug='{$a->slug}' name(pl)='" . $a->getTranslation('name', 'pl', false) . "' "
       . "wartosci=" . $a->values->count() . " przypisan=" . $used . "\n";
    foreach ($a->values as $v) {
        $c = DB::table('attribute_value_product')->where('attribute_value_id', $v->id)->count();
        echo "      wartosc id={$v->id} slug='{$v->slug}' name(pl)='" . $v->getTranslation('name', 'pl', false) . "' produktow=$c\n";
    }
}

// ── 1. Atrybut kanoniczny ────────────────────────────────────────────────────
// Preferuj dokładnie 'material'; jak nie ma — użyj istniejącego kandydata
// (np. 'material-1' po kolizji slugów), zamiast tworzyć duplikat.
$attribute = $candidates->firstWhere('slug', 'material') ?? $candidates->first();

if (!$attribute) {
    $attribute = new Attribute(['slug' => 'material', 'order' => (int) Attribute::max('order') + 1]);
    $attribute->setTranslations('name', $attributeName);
    if ($apply) {
        $attribute->save();
        $attribute->load('values');
    }
    echo "\nAtrybut: UTWORZONY slug='material'" . ($apply ? " id={$attribute->id}" : ' (dry-run)') . "\n";
} else {
    echo "\nAtrybut uzyty: id={$attribute->id} slug='{$attribute->slug}'\n";
    if ($attribute->slug !== 'material') {
        echo "  UWAGA: slug != 'material' — lista produktow w PIM szuka slug='material'.\n";
        echo "         Skrypt " . ($apply ? 'POPRAWIA' : 'poprawilby') . " slug na 'material'.\n";
        if ($apply) {
            DB::table('attributes')->where('id', $attribute->id)->update(['slug' => 'material']);
            $attribute->slug = 'material';
        }
    }
}

if (!$apply && !$attribute->exists) {
    echo "\n(dry-run: atrybut nie istnieje, dalsza czesc pokazuje tylko klasyfikacje)\n";
}

// ── 2. Wartości Stal / Aluminium ─────────────────────────────────────────────
$makeValue = function (string $slug, array $names) use ($attribute, $apply): ?AttributeValue {
    if (!$attribute->exists) return null;

    $value = AttributeValue::where('attribute_id', $attribute->id)
        ->where(fn($q) => $q->where('slug', $slug)->orWhere('slug', 'like', $slug . '-%'))
        ->first();

    if (!$value) {
        $value = new AttributeValue(['attribute_id' => $attribute->id, 'slug' => $slug]);
        $value->setTranslations('name', $names);
        if ($apply) $value->save();
        echo "  wartosc '$slug': UTWORZONA" . ($apply ? " id={$value->id}" : ' (dry-run)') . "\n";
        return $apply ? $value : null;
    }

    // slug po kolizji (np. 'stal-1') — wyprostuj, kontroler mapuje po slugu
    if ($value->slug !== $slug) {
        echo "  wartosc id={$value->id}: slug '{$value->slug}' -> '$slug'" . ($apply ? '' : ' (dry-run)') . "\n";
        if ($apply) {
            DB::table('attribute_values')->where('id', $value->id)->update(['slug' => $slug]);
            $value->slug = $slug;
        }
    }
    return $value;
};

echo "\n--- Wartosci ---\n";
$steel = $makeValue('stal', $steelName);
$alu   = $makeValue('aluminium', $aluName);

if (!$steel || !$alu) {
    echo "\n(dry-run bez istniejacych wartosci — pokazuje sama klasyfikacje ponizej)\n";
}

// ── 3. Klasyfikacja po KODZIE PRODUKTU ───────────────────────────────────────
$products = DB::table('products')->select('id', 'product_code', 'name')->get();

$targets = [];
$countAlu = $countSteel = $noCode = 0;
$mismatches = [];   // kod mowi co innego niz nazwa PL — do recznego przejrzenia

foreach ($products as $p) {
    $code   = (string) $p->product_code;
    $namePl = (string) (json_decode($p->name ?? '{}', true)['pl'] ?? '');

    if ($code === '') $noCode++;

    $isAlu = (bool) preg_match('/alu/i', $code);          // ← REGULA: wylacznie kod
    $byName = mb_stripos($namePl, 'alumin') !== false;

    if ($isAlu !== $byName && $namePl !== '') {
        $mismatches[] = "id={$p->id} kod='{$code}' nazwa='{$namePl}'";
    }

    $targets[$p->id] = $isAlu ? 'aluminium' : 'stal';
    $isAlu ? $countAlu++ : $countSteel++;
}

echo "\n--- Klasyfikacja po product_code ---\n";
echo "  produktow: " . count($targets) . "  (aluminium: $countAlu, stal: $countSteel)\n";
echo "  bez kodu produktu (traktowane jako stal): $noCode\n";

// ── 4. Pivot ─────────────────────────────────────────────────────────────────
if ($steel && $alu) {
    $valueIdBySlug = ['stal' => $steel->id, 'aluminium' => $alu->id];
    $bothIds = array_values($valueIdBySlug);

    $existing = DB::table('attribute_value_product')
        ->whereIn('attribute_value_id', $bothIds)
        ->get(['product_id', 'attribute_value_id'])
        ->groupBy('product_id');

    $now = date('Y-m-d H:i:s');
    $inserts = [];
    $toDelete = [];   // [product_id => [wrong value ids]]

    foreach ($targets as $productId => $slug) {
        $targetValueId = $valueIdBySlug[$slug];
        $current = collect($existing->get($productId, collect()))->pluck('attribute_value_id')->map(fn($i) => (int) $i);

        $wrong = $current->unique()->reject(fn($id) => $id === (int) $targetValueId);
        if ($wrong->isNotEmpty()) $toDelete[$productId] = $wrong->all();

        if (!$current->contains((int) $targetValueId)) {
            $inserts[] = [
                'attribute_value_id' => $targetValueId,
                'product_id'         => $productId,
                'created_at'         => $now,
                'updated_at'         => $now,
            ];
        }
    }

    $deleteCount = array_sum(array_map('count', $toDelete));
    echo "\n--- Pivot ---\n";
    echo "  do dodania:  " . count($inserts) . "\n";
    echo "  do usuniecia (bledny materiał): $deleteCount\n";

    if ($apply) {
        foreach ($toDelete as $productId => $wrongIds) {
            DB::table('attribute_value_product')
                ->where('product_id', $productId)
                ->whereIn('attribute_value_id', $wrongIds)
                ->delete();
        }
        foreach (array_chunk($inserts, 500) as $chunk) {
            DB::table('attribute_value_product')->insert($chunk);
        }
        Cache::forget('attributes');
        echo "  ZAPISANO. Cache 'attributes' wyczyszczony.\n";
    } else {
        echo "  (dry-run — nic nie zapisano)\n";
    }

    // kontrola
    $check = DB::table('attribute_value_product')
        ->whereIn('attribute_value_id', $bothIds)
        ->selectRaw('attribute_value_id, COUNT(*) c')->groupBy('attribute_value_id')->pluck('c', 'attribute_value_id');
    echo "  stan w bazie: stal=" . ($check[$steel->id] ?? 0) . ", aluminium=" . ($check[$alu->id] ?? 0) . "\n";
}

// ── 5. Rozjazdy kod vs nazwa ─────────────────────────────────────────────────
echo "\n--- Rozjazdy kod vs nazwa PL: " . count($mismatches) . " ---\n";
foreach (array_slice($mismatches, 0, 40) as $m) {
    echo "  $m\n";
}
if (count($mismatches) > 40) {
    echo "  ... i " . (count($mismatches) - 40) . " wiecej\n";
}

echo "\nGOTOWE" . ($apply ? '.' : ' (dry-run).') . "\n";
