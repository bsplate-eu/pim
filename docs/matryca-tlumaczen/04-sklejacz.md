# 04 — Sklejacz (ProductTranslationComposer)

Plik: `app/Services/ProductTranslationComposer.php`

## Co robi

Z polskiej nazwy produktu + matrycy fraz buduje nazwy we **wszystkich 11 kanałach**.

```
"Stalowa osłona silnika Alfa Romeo Mito"   (products.name->pl)
        │
        ├─ make = "Alfa Romeo", model = "Mito"   (z attributeValues)
        ├─ stripSuffix → pl_prefix = "Stalowa osłona silnika"
        ├─ TranslationPhrase::where('slug', slug("Stalowa osłona silnika"))
        │       └─ renditions: de="Stahl Unterfahrschutz für Motor", cs="Ocelový kryt motoru", ...
        └─ join: rendition + " " + make + " " + model
                de = "Stahl Unterfahrschutz für Motor Alfa Romeo Mito"
                cs = "Ocelový kryt motoru Alfa Romeo Mito"
                allegro_klapypodsilnik = "Stalowa płyta pod silnik Alfa Romeo Mito"
                ...
```

## Metody publiczne

### `compose(Product $p): array`
Zwraca propozycję BEZ zapisu:
```php
[
  'matched'   => true/false,
  'pl_prefix' => 'Stalowa osłona silnika',
  'make'      => 'Alfa Romeo',
  'model'     => 'Mito',
  'phrase_id' => 42,
  'channels'  => ['pl'=>..., 'de'=>..., ..., 'allegro_oslonypareto'=>...],  // null jeśli brak rendition
]
```

### `apply(Product $p): array`
Zapisuje propozycję do bazy:
- `products.name->{locale}` dla pl/de/cs/sk/fr/es — z lockiem `auto_matrix`
- `integration_products.overrides.name` per konto Allegro (13/14/16/17/18 + alias 12) — lock `int:{id}`
- **NIE nadpisuje** slotów już zablokowanych (manual/sheet_import/auto_matrix) → liczy je jako `skipped_locked`

Zwraca: `['matched', 'applied_locales', 'applied_integrations', 'skipped_locked']`.

## Logika `stripSuffix` (odcinanie marki/modelu)

- Odcina z KOŃCA: najpierw model, potem markę (case-insensitive, z opcjonalnymi spacjami/myślnikami).
- **Aliasy marek** (bo PL czasem skraca): `Volkswagen↔VW`, `Mercedes-Benz↔Mercedes`, `Alfa Romeo↔Alfa`, ...
- 3 passy (bo „VW Golf" wymaga: odetnij Golf → odetnij VW).

Te same stałe `BRAND_ALIASES` są w `TranslationsExploreSheet` i `TranslationsImportFromSheet` —
**przy zmianie aliasów trzymaj je zsynchronizowane** (3 pliki).

## Hook w SumpguardSource

Dla NOWEGO produktu, po `attributeValues()->sync()` (sklejacz potrzebuje make+model):
```php
app(\App\Services\ProductTranslationComposer::class)->apply($product->fresh(['attributeValues.attribute']));
```
Błąd sklejacza nie wywala importu (try/catch + Log::warning).

## Przypadki brzegowe (idą do review queue)

Sklejacz zwraca `matched=false` gdy:
- brak `name->pl`
- po stripie nie zostaje prefix
- prefix nie ma odpowiednika w `translation_phrases`

Typowe niedopasowania w danych Sumpguard:
| PL w produkcie | Problem |
|---|---|
| „Aluminium Osłona…" | matryca ma „Aluminiowa osłona…" — inny slug |
| „…Toyota Rav4" vs atrybut „RAV 4" | różny zapis modelu → suffix się nie odcina |
| literówki, warianty (Spacetourerr) | osobny slug |

Rozwiązanie: dopisać brakującą frazę w UI matrycy (`/admin/translation-phrases`) → „Reaplikuj".
