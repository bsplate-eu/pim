# Grid nadpisań integracji — funkcje

**Strona:** `/admin/integrations/{id}/products` ([IntegrationProduct/Edit.vue](../resources/js/crafter/Pages/IntegrationProduct/Edit.vue) + [Form.vue](../resources/js/crafter/Pages/IntegrationProduct/Form.vue))

## Czym są „nadpisania integracji"

Każda integracja (sklep PrestaShop / LiteCart / Selly / Baselinker) ma rekordy `integration_products` per produkt PIM. Pole JSON `overrides` przesłania bazowe wartości produktu przy wysyłce na sklep:

- `overrides.name` — zlokalizowana nazwa per sklep (np. SK „Oceľový kryt motora …")
- `overrides.ean`
- `overrides.enabled` — **flaga „aktywny do eksportu"** (kluczowa semantyka, patrz [connector-new-shop-recipe.md](../C:/Users/Pareto 1/.claude/projects/D--laragon-www-PIM/memory/connector_new_shop_recipe.md))

### Semantyka „aktywny do eksportu" — zachowana 1:1 z arkuszem Google
- `overrides` puste / brak `enabled` → decyduje bazowe `product.enabled`
- `overrides.enabled = "0"` → produkt **pomijany** przy eksporcie (`CatalogCreatePipeline` w linii ~197 czyta `overrides.enabled` przed `product.enabled`)
- `overrides.enabled = "1"` → produkt aktywny (nawet jeśli base wyłączony)

Migracja na grid **nie zmienia** semantyki — endpoint `update` buduje overrides identycznie jak stary `updateIntegration` (zbiera klucze `overrides_*` z payloadu, traktuje `0`/`"0"` jako wartość).

## Nagłówek strony

Trzy przyciski, identyczna logika jak na cenniku:

| Przycisk | Akcja |
|---|---|
| **Aktualizuj z CSV** | Upload CSV → upsert `integration_products.overrides` |
| **Eksport CSV** | Pobiera CSV w formacie identycznym ze starym arkuszem |
| **Save** | `PUT /admin/integrations/{id}/products` — upsert overrides z gridu |

## Grid kolumny

Pary „bazowe (read-only) / nadpisanie (edytowalne)":

| Kolumna | Charakter | Treść |
|---|---|---|
| Kod | read-only | `product_code` |
| Nazwa (bazowa) | read-only | `product.name` w locale templatki |
| Nazwa (nadpisanie) | edytowalne | `overrides.name` |
| EAN | read-only | `product.ean` |
| EAN (nadpisanie) | edytowalne | `overrides.ean` |
| Aktywny (bazowy) | read-only, sort numeryczny | `(int) product.enabled` |
| Aktywny (nadpisanie) | edytowalne, sort numeryczny | `overrides.enabled` (`"0"`/`"1"`) |

`keyField="product_id"`. Sortowanie + filtrowanie + edycja zakresu (wklejanie z Excela) działają identycznie jak w cenniku — wspólny komponent `<DataGrid>`.

## Backend — zachowane szczegóły

### `index()` — ładowanie danych
```
addAllEnabledProducts()         // zapewnia ze wszystkie produkty z sources sa w integration_products
↓
IntegrationProduct::with('product', 'integrationSource.template')
    ->where('integration_id', ...)
    ->get()
    ->filter(...->product)       // usuwa sieroty po usunietych produktach
    ->map(per item):
        setLocale(integrationSource.template.locale)  // np. 'fr' dla bspfr
        row = {
            product_id, external_id, product_code,
            name (base), override_name,
            ean (base), override_ean,
            enabled (base, int), override_enabled
        }
```

### `update()` — zapis
```
generateApiData()                 // zachowane: dla selly/baselinker regeneruje webhook key
↓
foreach row in payload.rows:
    overrides = {}
    foreach k in [name, ean, enabled]:
        v = row["override_$k"]
        if !empty(v) || v == 0:
            overrides[k] = v
    upsert {integration_id, product_id, overrides: json_encode($overrides)}
```

Klucz konfliktu: `['integration_id', 'product_id']`. To samo co stary `updateIntegration`, więc nie zmienia zachowania reszty systemu.

## CSV — format identyczny ze starym arkuszem

### Eksport
```
id, external_id, product_code, name, ean, enabled, overrides_name, overrides_ean, overrides_enabled
```

- `id` = `product.id`
- `external_id` = `product.external_id` (= `id_product` w PrestaShop)
- Kolumny bazowe (`name`, `ean`, `enabled`) są read-only — w pliku tylko jako odniesienie
- Tylko `overrides_*` są odczytywane przy imporcie

### Import — bezpieczeństwo
Dopasowanie po kolumnie `id`. **Uwzględniane są tylko produkty, które już są w `integration_products` dla tej integracji** — CSV z dowolnymi `id` spoza integracji **nie wstrzykuje sierot** (chronimy się przed `integration_products` bez `integration_source_id`, które wywaliłyby downstream pipeline'y).

Walidacja: muszą być kolumny `id` i co najmniej jedna `overrides_*`.

## Endpointy

| Metoda | URL | Akcja | Permission |
|---|---|---|---|
| GET | `/admin/integrations/{id}/products` | Ekran edycji nadpisań | `crafter.integration-product.edit` |
| PUT/PATCH | `/admin/integrations/{id}/products` | Zapis (rows) | `crafter.integration-product.edit` |
| GET | `/admin/integrations/{id}/products/export-csv` | Pobranie CSV | `crafter.integration-product.edit` |
| POST | `/admin/integrations/{id}/products/import-csv` | Upload CSV → upsert overrides | `crafter.integration-product.edit` |

## Co NIE jest zaimplementowane (świadomie)

- Operacje masowe (zmiana % / przeliczenie waluty) — operacje masowe są na cenniku. Na integracji nie ma sensu „przeliczenia waluty", a `enabled = 0/1` bulk-update można dorobić osobno na życzenie (patrz pytanie końcowe w sesji 2026-06-08).
- Wyszukiwarka — `<DataGrid>` ma prop `filter`, ale Form.vue integracji jeszcze nie podaje pól wyszukiwarki. Łatwe do dodania, ale czekam na ack od usera.
