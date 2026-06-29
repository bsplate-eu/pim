# Argo HQ — wdrożenie paczki, Zestawienia + Eksport, zmiany w zamówieniach

**Data prac:** 2026-05-19 – 2026-05-20
**Środowisko:** lokalne (Laragon, PHP 8.3.30, MySQL `pareto`, Node v18)
**Baza danych — backup przed pracami:** `baza danych/backup-pre-argohq-20260519-125607.sql` (40 MB)

Dokument opisuje **wszystkie** zmiany wykonane w tej sesji — w kolejności chronologicznej.

---

## Spis treści
1. [Wdrożenie paczki Argo HQ (Koszty + Kasa)](#1-wdrożenie-paczki-argo-hq-koszty--kasa)
2. [Usunięcie kolumny „Faktura" z Planera kosztów](#2-usunięcie-kolumny-faktura-z-planera-kosztów)
3. [Odyssey — usunięcie z menu i przywrócenie modułu](#3-odyssey--usunięcie-z-menu-i-przywrócenie-modułu)
4. [Nowy moduł „Zestawienia"](#4-nowy-moduł-zestawienia)
5. [Kolumna „Data utworzenia" + data w panelu zamówień](#5-kolumna-data-utworzenia--data-w-panelu-zamówień)
6. [Kolumna „Rodzaj dokumentu"](#6-kolumna-rodzaj-dokumentu)
7. [Przycisk „Eksport" (XLS księgowy)](#7-przycisk-eksport-xls-księgowy)
8. [Lista zamówień — dopasowanie do BaseLinkera](#8-lista-zamówień--dopasowanie-do-baselinkera)
9. [Stan bazy / migracje](#9-stan-bazy--migracje)
10. [Uwagi i tematy otwarte](#10-uwagi-i-tematy-otwarte)

---

## 1. Wdrożenie paczki Argo HQ (Koszty + Kasa)

Źródło: `+importy/argohq-package`. Wgrane moduły grupy „Argo HQ" (bez Wdrożeń).

**Skopiowane pliki (35):**
- `app/Http/Controllers/Admin/` — 8 kontrolerów: BankStatementItem, BankStatementMonth, CostPlannerItem, CostPlannerMonth, CostPlannerReport, CostPlannerSettings, Kasa, OdysseyCost
- `app/Models/` — 8 modeli: BankStatementItem, BankStatementMonth, CostPlannerItem, CostPlannerMonth, CostPlannerSettings, OdysseyCostMonth, OdysseyCostOrderEntry, OdysseyCostPayment
- `app/Services/BankStatement/` — 5 parserów CSV: BankStatementParser, BaseCsvParser, ParserFactory, PkoCsvParser, SantanderCsvParser
- `database/migrations/` — 4 migracje: `2026_04_18_000001_create_cost_planner_tables`, `2026_04_18_000002_add_cost_planner_settings_and_rework_items`, `2026_04_18_000003_create_bank_statement_tables`, `2026_04_24_120000_create_odyssey_cost_tables`
- `resources/js/crafter/Pages/` — 10 plików Vue: CostPlanner (Index, Show, Settings, Reports, NamedColorEditor), BankStatement (Index, Show), OdysseyCost (Index, Show), Kasa (Index)

**Zmodyfikowane pliki istniejące:**
- `routes/crafter.php` — dopisany blok `Argo HQ — Koszty + Kasa + Odyssey` (29 tras)
- `resources/js/crafter/Components/Sidebar.vue` — dodana grupa „Argo HQ" przed „Argo Connect" + import `BuildingOffice2Icon`
  - **Adaptacja:** paczka używała komponentu `SidebarSubGroup`, którego nie ma w tym projekcie → skonwertowane na zagnieżdżone `<SidebarGroup :toggable="false">`.

**Operacje na systemie:**
- Backup bazy `pareto` → `baza danych/backup-pre-argohq-20260519-125607.sql`
- `mkdir storage/app/bank-statements`
- `php artisan migrate` — 4 tabele cost_planner / bank_statement / odyssey
- `npm run build`

**Zależności (już obecne w projekcie):** Laravel 10 + Inertia, Vue 3, Tailwind, `phpoffice/phpspreadsheet`, `@heroicons/vue`, `@brackets/vue-toastification`. Parsery CSV nie wymagają dodatkowych pakietów.

---

## 2. Usunięcie kolumny „Faktura" z Planera kosztów

Plik: `resources/js/crafter/Pages/CostPlanner/Show.vue`.
- Usunięty nagłówek `<th>Faktura</th>` i komórka z inputem `invoice_number`.
- Poprawione `colspan` (pusty wiersz 11→10, stopka 6→5).
- Usunięte `invoice_number` z interfejsu TS.
- Kolumna `invoice_number` **pozostaje w bazie** (`cost_planner_items`) jako nieużywana — nie usuwano migracją.

---

## 3. Odyssey — usunięcie z menu i przywrócenie modułu

**Pierwotne polecenie** („wypierdol Odysseya") zostało zinterpretowane zbyt szeroko — usunięto cały moduł. **Następnie przywrócono** backend, zostawiając usuniętą tylko pozycję w menu.

**Stan końcowy Odysseya:**
- Z menu (`Sidebar.vue`) usunięta pozycja **„Koszty Odyssey"** (reszta Koszty nietknięta).
- Backend **w pełni działa**: `OdysseyCostController`, modele `OdysseyCost*`, migracja `2026_04_24_120000_create_odyssey_cost_tables`, 3 tabele (`odyssey_cost_months`, `odyssey_cost_order_entries`, `odyssey_cost_payments`), 9 tras `odyssey-cost.*`, widoki Vue `OdysseyCost/Index.vue` + `Show.vue`.
- Strona `/admin/odyssey-cost` dostępna z URL, ale bez linku w menu.

**Uwaga (efekt uboczny):** przy odtwarzaniu tabel Odysseya `php artisan migrate` uruchomił też **12 innych migracji `integration_*`**, które leżały na dysku jako pending (nie były częścią tej pracy). Wszystkie przeszły bez błędów — patrz [sekcja 9](#9-stan-bazy--migracje).

---

## 4. Nowy moduł „Zestawienia"

**Cel:** zakładka w Argo HQ → Koszty. Tworzysz miesiące (jak w Planerze). Do miesiąca **automatycznie** zaciągają się **faktury (FV) i korekty (KOR)** z Connect (`connect_invoices`), filtrowane po źródle zamówienia.

### Logika (pipeline)
- Jednostka = faktura/korekta z `connect_invoices` (nie zamówienie).
- Wchodzi pozycja, gdy powiązane zamówienie ma `order_source` ∈ dozwolonych źródeł **oraz** istnieje FV/KOR.
- **Korekta = osobny wiersz.**
- **Przypisanie do miesiąca = z numeru faktury** (`nr_full`, np. `37/5/2026/BSP` → maj 2026), **nie** z daty zamówienia.
- **Sortowanie** = po numerze `nr` rosnąco.
- Dane liczone **na żywo** — synchronizacja base automatycznie odświeża zestawienie (nie kopiujemy pozycji do osobnej tabeli).

### Filtr źródeł (edytowalna stała)
Plik: `app/Http/Controllers/Admin/CostPlannerSummaryController.php`, stała `ALLOWED_SOURCES`:
```php
private const ALLOWED_SOURCES = [
    'ebay',
    'BSP [DE]',
    'BSP DE',
    'bsp_black_steel_plate_gmbh',
];
```
> Po pierwszej synchronizacji zamówień BSP należy ustawić tu **dokładną** wartość `order_source` dla BSP.

### Pliki
- Migracja: `database/migrations/2026_05_20_120000_create_summary_months_table.php` (tabela `summary_months`: year, month, label, notes, unique(year,month))
- Model: `app/Models/SummaryMonth.php`
- Kontroler: `app/Http/Controllers/Admin/CostPlannerSummaryController.php` — `index / store / show / destroy / refresh / export`; metoda prywatna `collectRows()` (JOIN `connect_invoices` → `orders`, z fallbackiem źródła/daty po fakturze nadrzędnej dla korekt) + `parseNrFull()`
- Widoki: `resources/js/crafter/Pages/Summaries/Index.vue` (lista miesięcy + „Dodaj miesiąc"), `resources/js/crafter/Pages/Summaries/Show.vue` (tabela pozycji)
- Trasy w `routes/crafter.php` (przed wildcardem `cost-planner/{costPlannerMonth}`):
  `cost-planner.summaries.index/store/show/destroy/refresh/export`
- Menu: pozycja **„Zestawienia"** w Argo HQ → Koszty (między Raporty a Wyciąg z konta)

> Wcześniejszy placeholder `CostPlanner/Summaries.vue` został usunięty (zastąpiony folderem `Summaries/`).

### Weryfikacja
Pipeline sprawdzony na realnych danych (`order_source = ebay`):
| Miesiąc (z numeru) | FV | KOR |
|---|---|---|
| Maj 2026 | 35 | 2 |
| Kwiecień 2026 | 55 | 4 |
| Marzec 2026 | 53 | 3 |
| Luty 2026 | 6 | – |

Pozycji BSP: **0** — bo żadne zamówienie nie ma jeszcze `order_source` = BSP (filtrujemy po źródle zamówienia, nie po serii faktury „Faktura BSP").

---

## 5. Kolumna „Data utworzenia" + data w panelu zamówień

**Data utworzenia dokumentu = `connect_invoices.issue_date`** (data wystawienia z BaseLinkera). Wypełnione na 100% pozycji (FV i KOR). `payment_date` jest puste; `created_at` to tylko data importu do naszej bazy.

- **Panel zamówień** (`resources/js/crafter/Pages/Connect/Orders/Show.vue`, sekcja „Faktury i korekty") — etykieta **„Utworzono: DD.MM.RRRR"** przy każdej fakturze/korekcie.
- **Zestawienia** — nowa kolumna **„Data utworzenia"** (między „Data zamówienia" a „Źródło"). Kontroler dorzuca `issue_date` do `collectRows()`.

---

## 6. Kolumna „Rodzaj dokumentu"

W `Summaries/Show.vue` dodana kolumna **„Rodzaj dokumentu"**:
- Faktura (FV) → **`Rechnung`**
- Korekta → **`Korrekturrechnung`**

Wartość liczona w kontrolerze jako pole `doc_type_de` (gotowa do użycia w eksporcie).

**Układ tabeli Zestawień:** `# · Data zamówienia · Data utworzenia · Źródło · Numer FV · Typ · Rodzaj dokumentu`.

---

## 7. Przycisk „Eksport" (XLS księgowy)

Przycisk **Eksport** w widoku miesiąca (obok „Odśwież") generuje plik **`.xls`** w układzie zgodnym z wzorcem `Tabelle 4-2026 Verkauft.csv.xls` (niemiecki eksport księgowy, DATEV-style).

**Format pliku:**
- Writer: `phpoffice/phpspreadsheet` → `PhpOffice\PhpSpreadsheet\Writer\Xls`
- Arkusz: `Tabelle {miesiąc}-{rok} Verkauft`
- Nazwa pliku: `Tabelle {miesiąc}-{rok} Verkauft.xls`
- Nagłówki (wiersz 1) zachowane 1:1 ze wzorca, łącznie ze spacją w `"Buchungstext "`.

**Mapowanie kolumn:**
| Kol. | Nagłówek | Wartość |
|---|---|---|
| A | Datum | `issue_date` (format `DD.MM.RRRR`, jako tekst) |
| B | Betrag | — puste |
| C | S/H | — puste |
| D | Gegenkonto | — puste |
| E | Konto | — puste |
| F | Rechnungsnummer | `nr_full` (jako tekst — nie zamienia się w datę) |
| G | Buchungstext | `Rechnung` / `Korrekturrechnung` |

Pozycje sortowane po numerze; korekty jako osobne wiersze.

**Pliki:** metoda `export()` w `CostPlannerSummaryController` + trasa `cost-planner.summaries.export` + przycisk w `Summaries/Show.vue`.

**Weryfikacja end-to-end** (Maj 2026): wygenerowany `.xls`, arkusz `Tabelle 5-2026 Verkauft`, 38 wierszy (1 nagłówek + 37 pozycji), korekty poprawnie jako `Korrekturrechnung`, `nr_full` zachowany jako tekst.

---

## 8. Lista zamówień — dopasowanie do BaseLinkera

Plik: `resources/js/crafter/Pages/Connect/Orders/Index.vue` + serializacja w `app/Http/Controllers/Admin/Connect/OrderController.php`.

- **Kolumna „Nr"**: dodana **nazwa klienta** (`delivery_fullname`) obok flagi; źródło/odbiór przeniesione do osobnej linii z ikoną `UserIcon`.
- **Kolumna „Szczegóły"**: dodane **„Firma:"** (`invoice_company`).
- Kontroler serializuje teraz dodatkowo `invoice_company` + `invoice_fullname`.

Pozostałe elementy listy już wcześniej odwzorowywały BaseLinker (miniatury + nazwa + SKU, kwota + status opłacenia, badge statusu, metoda dostawy, znaczniki P/S/F, daty, gwiazdka).

---

## 9. Stan bazy / migracje

Nowe/odtworzone tabele w bazie `pareto`:
- `cost_planner_months`, `cost_planner_items`, `cost_planner_settings`
- `bank_statement_months`, `bank_statement_items`
- `odyssey_cost_months`, `odyssey_cost_order_entries`, `odyssey_cost_payments`
- `summary_months`

**Batche migracji (końcowo):**
- **23**: `cost_planner` (×2) + `bank_statement` (3 migracje)
- **24**: `connect_invoices` (×2) — `2026_05_19_120000_create_connect_invoices_table`, `2026_05_19_120001_add_last_invoice_id_to_connect_base_settings` (praca równoległa, **nietknięte**)
- **25**: ponowna migracja Odysseya + **12 migracji `integration_*`** uruchomionych mimochodem przy `php artisan migrate` (były pending na dysku): integration_sync_logs, integration_categories, integration_entity_states, integration_media_queue, integration_analytics, integration_blog_mappings, add_payload_hash*, widen_integrations_for_encryption, add_webhook_secret_to_integrations + odyssey.
- **20**: `summary_months` (`2026_05_20_120000`)

> Tabele `connect_invoices`: 1280 faktur + 13 korekt. Serie m.in. „Faktura BSP" (188), „Korekta BSP" (12), „domyślna" (1067).

---

## 10. Uwagi i tematy otwarte

- **Filtr źródeł Zestawień** — `ALLOWED_SOURCES` zawiera warianty `ebay / BSP [DE] / BSP DE / bsp_black_steel_plate_gmbh`. Po pierwszej synchronizacji BSP ustawić dokładną wartość `order_source`. Dziś realnie łapie się tylko `ebay`.
- **Eksport kolumny B–E** (Betrag / S/H / Gegenkonto / Konto) — zgodnie z mapowaniem **puste**. Do uzupełnienia regułami, gdy klient poda logikę księgowania.
- **12 migracji `integration_*`** (batch 25) zostały uruchomione mimochodem; przeszły czysto. Jeśli nie powinny — do rozważenia rollback (uwaga: batch 25 zawiera też Odysseya).
- **Kolumna `invoice_number`** w `cost_planner_items` — nieużywana po usunięciu kolumny „Faktura"; można usunąć osobną migracją.

### Wdrożenie na produkcji (skrót)
1. Wgraj pliki źródłowe (`app/`, `resources/`, `routes/`, `database/migrations/`).
2. `composer install --no-dev -o` (jeśli zmieniły się zależności — tu nie).
3. `php artisan migrate` (utworzy m.in. `summary_months`).
4. `npm ci && npm run build` — **po** wgraniu świeżych `.vue`.
5. `php artisan optimize:clear` + cache.
6. Upewnij się, że istnieje `storage/app/bank-statements/` (dla modułu Wyciąg z konta).
