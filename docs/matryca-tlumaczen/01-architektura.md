# 01 — Architektura

## Tabele DB

### `translation_phrases` — frazy (typy produktów)
Migracja: `2026_05_28_100000_create_translation_phrases_table.php`

| Kolumna | Typ | Opis |
|---|---|---|
| `id` | bigint | PK |
| `slug` | varchar(200) UNIQUE | `Str::slug(phrase_pl, '_')` — klucz wyszukiwania |
| `phrase_pl` | text | Polski wzorzec, np. „Stalowa osłona silnika" (BEZ marki/modelu) |
| `product_count` | int | Ile produktów używa tej frazy (statystyka) |

Fraza = **typ produktu** po odcięciu marki i modelu. Przykład:
`"Stalowa osłona silnika Alfa Romeo Mito"` − `make=Alfa Romeo` − `model=Mito` → **`"Stalowa osłona silnika"`**

### `translation_phrase_renditions` — tłumaczenia per kanał
Migracja: `2026_05_28_100001_create_translation_phrase_renditions_table.php`

| Kolumna | Typ | Opis |
|---|---|---|
| `translation_phrase_id` | FK | → translation_phrases |
| `channel` | varchar(60) | `pl`/`de`/`cs`/`sk`/`fr`/`es`/`allegro_*` |
| `value` | text | Tłumaczenie frazy (BEZ marki/modelu), np. „Stahl Unterfahrschutz für Motor" |
| `source` | varchar(20) | `sheet_import` / `manual` |
| `variants_count` | int | Ile różnych wartości arkusz miał dla tej pary (fraza, kanał) — sygnał niespójności |

UNIQUE `(translation_phrase_id, channel)`.

### `translation_overrides` — chronione sloty (lock)
Migracja: `2026_05_28_100002_create_translation_overrides_table.php`

| Kolumna | Typ | Opis |
|---|---|---|
| `translatable_type` | varchar(60) | Morph, np. `App\Models\Product` |
| `translatable_id` | bigint | ID encji |
| `field` | varchar(40) | `name` / `info_1` / `overrides.name` |
| `locale` | varchar(60) | `pl`/`de`/... LUB `int:13` (dla overrides per integracja) LUB `default` |
| `source` | varchar(20) | `manual` / `sheet_import` / `ai` / `auto_matrix` |
| `user_id` | FK nullable | Kto ustawił (jeśli edycja ręczna w UI) |
| `locked_at` | timestamp | Kiedy |

UNIQUE `(translatable_type, translatable_id, field, locale)`.

**To jest serce ochrony** — obecność wpisu = „tego slotu automat NIE może nadpisać".

---

## Modele

- `App\Models\TranslationPhrase` — `hasMany(renditions)`, helper `rendition($channel)`
- `App\Models\TranslationPhraseRendition` — `belongsTo(phrase)`
- `App\Models\TranslationOverride` — statyczne helpery:
  - `isLocked($model, $field, $locale): bool`
  - `lockedLocales($model, $field): array`
  - `mark($model, $field, $locale, $source, $userId)` — upsert wpisu
  - `TranslationOverride::$suppressObserver` — flaga procesowa (patrz [03](03-ochrona-i-observers.md))
  - `LOCKING_SOURCES = [manual, sheet_import, auto_matrix]` — źródła które blokują automat

---

## Przepływ danych (3 ścieżki)

### A. Import historyczny (jednorazowo, z arkusza XLSX)
```
Dane do matrycowania.xlsx
   └─ translations:import-from-sheet
        ├─ products.name ← kolumny PL/DE/CS/SK/FR/ES        + lock 'sheet_import'
        ├─ integration_products.overrides.name ← 5 kont Allegro + lock 'sheet_import'
        └─ translation_phrases + renditions  (matryca, top-vote per kanał)
```

### B. Nowy produkt (cron Sumpguard 01:00)
```
SumpguardSource::getProducts()
   ├─ Product::create(enabled=false)           ← nowy, wyłączony
   ├─ attributeValues()->sync()                ← make, model, protection...
   └─ ProductTranslationComposer::apply()      ← sklejacz z matrycy
        ├─ match frazy → wypełnia 6 locale + 5 Allegro   + lock 'auto_matrix'
        └─ brak matcha → puste sloty → produkt do REVIEW QUEUE
```

### C. Aktualizacja istniejącego (cron Sumpguard)
```
SumpguardSource::getProducts()  (gałąź "produkt istnieje")
   ├─ lockedLocales = TranslationOverride::lockedLocales(product, 'name')
   ├─ usuń z payloadu wszystkie zablokowane sloty name->{locale}
   └─ update() tylko niezablokowanych + pól nie-tłumaczonych (cena/EAN/kategoria)
```

---

## Mapowanie kanał → miejsce zapisu

| Kanał | Gdzie ląduje | Integracja |
|---|---|---|
| `pl` `de` `cs` `sk` `fr` `es` | `products.name->{locale}` | — |
| `allegro_klapypodsilnik` | `integration_products.overrides.name` | 13 |
| `allegro_czescipareto` | `integration_products.overrides.name` | 14 |
| `allegro_dolneoslony` | `integration_products.overrides.name` | 16 |
| `allegro_ksteileshop` | `integration_products.overrides.name` | 17 |
| `allegro_oslonypareto` | `integration_products.overrides.name` | 18 |
| (alias) `allegro_oslonypareto` | `integration_products.overrides.name` | 12 (oslonypareto_pl) |

Definicja mapy: stałe w `ProductTranslationComposer` oraz `TranslationsImportFromSheet`.
