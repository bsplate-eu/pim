# 03 — Ochrona tłumaczeń (lock) i observerzy

## Idea

Każda **ręczna** edycja tłumaczenia zostawia ślad w `translation_overrides`.
Automaty (Sumpguard sync, sklejacz) **sprawdzają ten ślad** i NIE ruszają oznaczonych slotów.

Źródła (kolumna `source`) i ich znaczenie:

| source | Kto ustawia | Blokuje automat? |
|---|---|---|
| `manual` | User w UI (observer) | ✅ TAK |
| `sheet_import` | `translations:import-from-sheet` | ✅ TAK |
| `auto_matrix` | `ProductTranslationComposer` | ✅ TAK |
| `ai` | (rezerwacja na przyszłość) | — |

`LOCKING_SOURCES = [manual, sheet_import, auto_matrix]` w `TranslationOverride`.

---

## Observerzy

### `TranslationTrackingObserver`
Podpięty pod: `Product`, `Category`, `AttributeValue` (w `AppServiceProvider::boot()`).

Hook `saving`:
1. Iteruje pola `$model->translatable` (np. `name`, `info_1`...).
2. Dla każdego `isDirty($field)` porównuje JSON przed/po → wykrywa zmienione `(locale, value)`.
3. Dla każdego zmienionego slotu woła `TranslationOverride::mark(..., source='manual')`.

Hook `deleted`: kasuje wszystkie override danej encji.

### `IntegrationProductTrackingObserver`
Podpięty pod: `IntegrationProduct`.

Pole `overrides` to NIE multi-locale JSON, tylko per-integracja string.
Śledzi zmiany `overrides.name` / `overrides.ean` / `overrides.description`
i zapisuje override z `locale = "int:{integration_id}"`.

---

## `$suppressObserver` — flaga procesowa

```php
TranslationOverride::$suppressObserver = true;
try {
    $product->update($data);   // ten zapis NIE oflaguje się jako 'manual'
} finally {
    TranslationOverride::$suppressObserver = false;
}
```

**Po co:** importy i sklejacz zapisują programatycznie. Bez suppress każdy taki zapis
oflagowałby slot jako `manual` (przez observera) — i kolejny automat by go już nie ruszył.
Suppress oddziela „user kliknął" od „kod zapisał".

Używane w: `TranslationsImportFromSheet`, `ProductTranslationComposer`, `SumpguardSource`,
`TranslationsRepairEncoding`, `TranslationReviewController::approve`.

---

## Jak Sumpguard honoruje lock

`SumpguardSource::getProducts()` — gałąź „produkt istnieje":

```php
$lockedLocales = TranslationOverride::lockedLocales($product, 'name');  // np. [pl,de,cs,sk,fr,es]
if (!empty($lockedLocales) && is_array($data['name'])) {
    foreach ($lockedLocales as $l) unset($data['name'][$l]);
    if (empty($data['name'])) {
        unset($data['name']);              // wszystkie sloty chronione → nie ruszaj kolumny
    } else {
        // zostaw niezablokowane + dołącz istniejące zablokowane (merge, nie nadpisuj)
        $existing = $product->getTranslations('name');
        foreach ($lockedLocales as $l) if (isset($existing[$l])) $data['name'][$l] = $existing[$l];
    }
}
TranslationOverride::$suppressObserver = true;
try { $product->update($data); } finally { TranslationOverride::$suppressObserver = false; }
```

⚠️ **Pułapka której uniknięto:** jeśli po wyfiltrowaniu zablokowanych `$data['name']` zostanie
pustą tablicą `[]`, Spatie zapisałby `{}` i **wyczyścił** całe tłumaczenie. Dlatego `unset($data['name'])`
gdy pusto.

---

## Dodatkowa zmiana w SumpguardSource: koniec kopiowania PL do wszystkich locale

Wcześniej `buildNameTranslations()`, `synchronizeAttributes()`, `getAttributes()` kopiowały
polski tekst do **wszystkich 14 locale**. To był główny powód „polski w slocie DE/FR".

Teraz wpisują **tylko domyślny locale** (`app()->getLocale()`):
```php
// było: foreach ($this->locales as $l) $names[$l] = $name;
// jest:
$default = app()->getLocale() ?: 'en';
return [$default => $base];
```
Pozostałe sloty zostają **puste** → wypełni je matryca / sklejacz / ręczna edycja.

---

## Szybki test (tinker)

```php
// 1. Ręczna edycja → flaga manual
$p = App\Models\Product::find(1115);
$p->setTranslation('name', 'de', 'TEST'); $p->save();
App\Models\TranslationOverride::isLocked($p, 'name', 'de'); // true, source=manual

// 2. Symulacja Sumpguard (suppress) → NIE zmienia source na manual
App\Models\TranslationOverride::$suppressObserver = true;
$p->setTranslation('name','de','POLSKI'); $p->save();
App\Models\TranslationOverride::$suppressObserver = false;
// source nadal = poprzednie
```
