# Migracja Google Sheets → natywny grid w PIM (przegląd)

**Data:** 2026-06-08
**Status:** wdrożone na branch (lokalnie); do uruchomienia na prodzie krok-po-kroku opisany w [2026-06-08-grid-wdrozenie.md](2026-06-08-grid-wdrozenie.md).

## Cel

Wyciąć zależność od osadzonych arkuszy Google przy edycji **cenników** (`pricelist_product`) i **nadpisań integracji** (`integration_products.overrides`). Edytorem ma być natywny grid w PIM (Vue/Inertia) wpięty wprost w istniejące tabele. Google znika z tej ścieżki.

## Co dokładnie zostało zmienione

| Obszar | Przed | Po |
|---|---|---|
| Cenniki (edycja) | iframe Google Sheet + sync DB↔arkusz na otwarciu/zapisie | Natywny grid (`<DataGrid>`) wpięty w `pricelist_product` |
| Nadpisania integracji | jw. dla `integration_products.overrides` | jw. — drugi grid z parami „bazowe / nadpisanie" |
| `GoogleSheetsService` | używany przez 3 kontrolery | **skasowany w całości** |
| Trasy | `pricelists/sync/*`, `integrations/sync-sheet/*` | **usunięte** |
| Pakiet `revolution/laravel-google-sheets` | używany przez serwis + Baselinker | **zostaje** (Baselinker raport go nadal używa) |

## Co NIE zostało dotknięte

- **Raport zysków/zamówień Baselinker** (`BaselinkerSheetUpdate` command) — odrębny moduł z formułami, miesięcznymi zakładkami i danymi z API BL; przeniesienie tego do PIM to osobny, większy projekt.
- Konfiguracja Google Sheets (`config/sheets.php`) — pozostawiona, bo używa jej komenda jednorazowego importu (`sheets:import-final`) i dalej Baselinker.

## Nowe pliki (po stronie Laravel/Vue)

### Backend
- `app/Console/Commands/SheetsImportFinal.php` — jednorazowy import Google → baza (do uruchomienia na prodzie przy wdrożeniu).
- `app/Http/Controllers/Admin/ExchangeRateController.php` — endpoint kursów NBP z dnia poprzedniego (cache 6h).

### Frontend
- `resources/js/crafter/Components/DataGrid.vue` — reużywalny komponent gridu (RevoGrid pod spodem) z selekcją, filtrami, sortowaniem, auto-height.

### Zależności npm
- `@revolist/vue3-datagrid@4.23.3` (MIT) — Vue wrapper RevoGrid + `@revolist/revogrid@4.23.3`.

## Pliki zmodyfikowane

### Backend
- `app/Http/Controllers/Admin/PricelistController.php`:
  - `edit/update` — bez Google, ładowanie produktów z cenami z bazy + upsert na zapisie.
  - `store/destroy/bulkDestroy` — usunięto zależność od `GoogleSheetsService`.
  - `sync()` — **usunięta**.
  - Nowe metody: `clone`, `exportCsv`, `importCsv`, `readCsv`, `normalizePrice`, `uniqueName`, `uniqueSlug`.
- `app/Http/Controllers/Admin/IntegrationProductController.php`:
  - `index/update` — bez Google, ładowanie integration_products + upsert overrides.
  - Nowe metody: `exportCsv`, `importCsv`, `readCsv`, `cellValue`.
- `app/Http/Controllers/Admin/IntegrationController.php`:
  - `store/edit` — wycięte wywołania `createIntegration` (Google).
  - `syncSheet()` — **usunięta**.
- `app/Http/Requests/Admin/Pricelist/UpdatePricelistRequest.php` — walidacja `rows[]`.
- `routes/crafter.php` — nowe trasy (`exportCsv`, `importCsv`, `clone`, `exchange-rates.nbp`), usunięte `pricelists.sync` i `integrations.sync-sheet`.

### Frontend
- `resources/js/crafter/Components/index.ts` — eksport `DataGrid`.
- `resources/js/crafter/Pages/Pricelist/Edit.vue` — przyciski w nagłówku (Aktualizuj z CSV / Eksport CSV / Save), upload pliku, eksport.
- `resources/js/crafter/Pages/Pricelist/Form.vue` — grid + wyszukiwarka + modal operacji masowych.
- `resources/js/crafter/Pages/Pricelist/Index.vue` — przycisk **Stwórz kopię** między Edit a Delete.
- `resources/js/crafter/Pages/Pricelist/types.d.ts` — typ `PriceRow`, rozszerzony `PricelistForm` o `rows`.
- `resources/js/crafter/Pages/IntegrationProduct/Edit.vue` — j.w. dla integracji (CSV import/eksport).
- `resources/js/crafter/Pages/IntegrationProduct/Form.vue` — grid z kolumnami bazowymi + override.
- `resources/js/crafter/Pages/Integration/types.d.ts` — typy `OverrideRow`, `IntegrationProductsForm`.

### Skasowane
- `app/Services/GoogleSheetsService.php` — kompletnie wycięty.

## Architektura w pigułce

```
[Edit page Vue (Pricelist/IntegrationProduct)]
        ↓
   <DataGrid v-model="rows" :columns :filter keyField selectable>
        ↓
   RevoGrid (web component, MIT)
        ↑
   Edycje wracają przez @afteredit
        ↓
   form.rows (Inertia useForm)
        ↓
   PUT /admin/pricelists/{id}  (lub /admin/integrations/{id}/products)
        ↓
   Kontroler PHP → upsert do pricelist_product / integration_products.overrides
```

## Dokumenty pokrewne

- [2026-06-08-grid-cennik.md](2026-06-08-grid-cennik.md) — funkcje edycji cennika
- [2026-06-08-grid-integracje.md](2026-06-08-grid-integracje.md) — funkcje edycji nadpisań integracji
- [2026-06-08-grid-wdrozenie.md](2026-06-08-grid-wdrozenie.md) — kroki wdrożenia na prod
