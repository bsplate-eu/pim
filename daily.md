# daily.md — Dziennik projektu PIM (Argo)

> Podsumowanie **wszystkiego, co do dziś (2026-06-10) zostało zrobione** w projekcie `D:\laragon\www\PIM`.
> Projekt **nie jest pod gitem** — historia odtworzona z migracji bazy, listy kontrolerów oraz istniejących dzienników wdrożeń (patrz [sekcja 7](#7-szczegółowe-dzienniki-gdzie-szukać)).

---

## 1. Stack / środowisko

- **Backend:** Laravel 10 (PHP 8.1+, lokalnie 8.3.30), MySQL 8 (baza lokalna `pareto`, prod `admin_pim`).
- **Frontend:** Inertia.js + Vue 3 + Tailwind (panel administracyjny „crafter"), Vite (build produkcyjny), Ziggy (route w JS).
- **Kluczowe pakiety:** Spatie (permission, medialibrary, tags, settings, translation-loader, query-builder), `maatwebsite/excel` + `phpoffice/phpspreadsheet`, `openai-php/laravel` (AI), `mdev/laravel-prestashop`, `revolution/laravel-google-sheets`, `kalnoy/nestedset` (kategorie zagnieżdżone), `symfony/dom-crawler`, Sanctum.
- **Instancja:** `APP_INSTANCE=pareto` — **hardcode atrybutów** `make / model / year-start / year-stop` w eksporcie Prestashop (kluczowe dla sklepu, chronione przy wszystkich zmianach).
- **Lokalny adres:** `http://pim.test`. **Prod:** `pim.bsplate.eu` (DirectAdmin, PHP 8.3, bez git/scp/npm na serwerze).

---

## 2. Czym jest projekt

PIM (Product Information Management) rozbudowany do pakietu **„Argo"** — panel admina złożony z modułów:

| Moduł | Zakres |
|---|---|
| **Argo PIM** (katalog) | Produkty, Kategorie (nested set), Atrybuty + Wartości atrybutów, Szablony (opisy, lokalizowane), Cenniki, Źródła, Media (Spatie medialibrary), Tagi |
| **Integracje / Konektory** | Synchronizacja katalogu do sklepów: **Prestashop, LiteCart, Selly, Google Sheets**. Pipeline jobów (CatalogCreate/Delta, Media, Blog, Analytics), logi synchronizacji, statusy encji, szyfrowanie sekretów (key/url/sheet_id), webhooki (HMAC) |
| **Argo Connect** | Integracja z **BaseLinker** (multi-base): Zamówienia + produkty zamówień, Klienci, Faktury/Korekty, Mapa (geo_postal_codes), słownik statusów, logi sync |
| **Argo HQ** (finanse) | **Planer kosztów** (CostPlanner + raporty + ustawienia), **Wyciągi bankowe** (parsery CSV PKO/Santander), **Kasa**, **Odyssey**, **Zestawienia** (eksport księgowy XLS, format DATEV/niemiecki) |
| **Argo Task** (PM) | Projekty, Grupy projektów, Zadania (board z konfiguracją), Przypisania, Aktywności, Załączniki, Wzmianki (@mention), Powiadomienia, Podgląd linków |
| **AI Tools** | Narzędzia oparte o OpenAI (`openai-php/laravel`) |
| **Admin / System** | Auth (logowanie, rejestracja, reset hasła, weryfikacja e-mail), Role i uprawnienia (Spatie), Użytkownicy admin + zaproszenia, Ustawienia, Tłumaczenia (z bazy) |

---

## 3. Historia chronologiczna (wg dat migracji)

| Okres | Co powstało |
|---|---|
| **2014–2019** | Baza Laravel: users, password resets, failed jobs, personal access tokens |
| **2023-05** | Szkielet panelu admina: admin_users + reset haseł, **uprawnienia i role (Spatie)**, language_lines (tłumaczenia z DB), tags, media, settings, general_settings, unassigned_media |
| **2024-08** | **Rdzeń PIM:** products, templates (+ locale), pricelists (+ pricelist_product), uprawnienia produktów/cenników/szablonów |
| **2024-09** | **Integracje v1:** integrations, integration_products (+ external_id, manufacturer, sheet_id, enabled), jobs queue, info/short_description na produktach/szablonach |
| **2024-10** | **System atrybutów:** attributes, attribute_values, attribute_value_product (atrybuty wyniesione z tabeli products → relacja), sources, EAN na produktach |
| **2025-04** | **Kategorie (nested set):** categories, category_product; refactor products; integration_sources; drop template z integrations; order/meta na atrybutach i szablonach |
| **2025-05** | **AI Tools:** ai_tools + uprawnienia; meta na produktach |
| **2025-11** | external_id na kategoriach |
| **2026-03-29** | **Integracje v2 (konektory):** integration_sync_logs (+ errors), blog_id na integration_sources |
| **2026-04-12→15** | **Pipeline konektorów:** integration_categories, payload_hash + state (produkty/kategorie), integration_entity_states, integration_media_queue, integration_analytics, integration_connector_runs, integration_blog_mappings, **szyfrowanie sekretów** (widen + encrypt) |
| **2026-04-15→19** | **Argo Task:** argo_projects (+ board_config, deployment fields), argo_tasks (+ content), assignees, activities, project_groups, notifications |
| **2026-04-15→24** | **Argo Connect (BaseLinker):** connect_base_settings, orders (+ products, status_dictionary, sync_logs), customers, **multi-base** (label + base_settings_id) |
| **2026-04-18→24** | **Argo HQ:** cost_planner (+ settings), bank_statement, odyssey_cost |
| **2026-05-08→18** | order na sources, filtry sync BaseLinkera, webhook_secret na integracjach, **geo_postal_codes** (mapa) |
| **2026-05-19→20** | **connect_invoices** (faktury/korekty z BaseLinkera) + last_invoice_id, **summary_months** (Zestawienia) |

---

## 4. Dziennik ostatnich prac (2026-05-19 → 2026-06-10)

To są sesje, które realnie wykonywano w ostatnich dniach — każda ma własny szczegółowy dziennik (linki w [sekcji 7](#7-szczegółowe-dzienniki-gdzie-szukać)).

### 4.1 Argo HQ — Zestawienia + Eksport księgowy (2026-05-19/20)
- Wdrożono paczkę **Argo HQ** z `+importy/argohq-package` (8 kontrolerów, 8 modeli, 5 parserów CSV, 4 migracje, 10 widoków Vue) — Planer kosztów, Wyciągi bankowe, Kasa, Odyssey.
- Z Planera kosztów usunięto kolumnę „Faktura" (kolumna `invoice_number` została w bazie jako nieużywana).
- **Odyssey:** usunięto tylko pozycję z menu; backend (kontroler, modele, 3 tabele, 9 tras, widoki) pozostaje sprawny.
- **Nowy moduł „Zestawienia"** (Argo HQ → Koszty): miesiące zaciągają **automatycznie FV/KOR z `connect_invoices`**, filtrowane po źródle zamówienia (`ALLOWED_SOURCES`), miesiąc liczony **z numeru faktury** (`nr_full`), korekta = osobny wiersz, dane liczone na żywo.
- Dodano kolumny **„Data utworzenia"** (`issue_date`) i **„Rodzaj dokumentu"** (`Rechnung` / `Korrekturrechnung`).
- **Przycisk „Eksport"** → plik `.xls` (PhpSpreadsheet, układ DATEV: Datum / Betrag / S/H / Gegenkonto / Konto / Rechnungsnummer / Buchungstext). Zweryfikowane E2E (maj 2026: 37 pozycji).
- Lista zamówień Connect dopasowana do BaseLinkera (nazwa klienta, firma, źródło/odbiór).
- Stan danych: `connect_invoices` = 1280 faktur + 13 korekt.

### 4.2 Konektory + dział Integracje (2026-05-20)
- Synchronizacja wersji z `+importy/connectors-package` (z 64 plików paczki: 16 identycznych, 18 nowych, 30 różniących się).
- **Hardcode `make/model/year-start/year-stop` zachowany bit-for-bit** (warunek `APP_INSTANCE=pareto`). Zweryfikowany E2E na Integration#2 „Prestashop TEST".
- Wgrano 18 nowych plików (kontroler statusu sync, 6 jobów konektorów, 7 modeli, `config/integrations.php`, widok `Status.vue`, definicje konektorów presta/litecart) + scalono 24 kolidujące pliki PHP.
- **Szyfrowanie sekretów:** napisano idempotentną, odwracalną migrację `encrypt_existing_integration_secrets` (key/url/sheet_id → encrypted casts).
- Dodano trasy `integrations.status` / `.status.json` / `.status.stop-all` / `sync-connector`.
- **Blog = Future-Proof / uśpiony** — moduł Blog jeszcze nie istnieje; 4 punkty wstrzymania oznaczone `// TODO: enable when Blog module exists` (kolumna `blog_id` bez FK, relacja i opcje zakomentowane).
- Usunięto martwy `IntegrationProductsExport.php` (relacja `template()` usunięta migracją w 2025-04).
- Backup przed pracami: `+importy/backup-przed-connectors-20260520_104330.sql` (42,5 MB).

### 4.3 Sidebar — redesign + zwijanie lewej kolumny (2026-05-20)
- Nowa **granatowa paleta `sidebar`** w `tailwind.config.js` (główny `#15275a`).
- Utworzono: hook `useSidebarActive.ts` (auto-rozwijanie aktywnej grupy), `SidebarSubGroup.vue`, `SidebarSubGroupNavLink.vue`.
- Przepisano `Sidebar.vue`, `SidebarGroup.vue`, `SidebarItem.vue`, `Authenticated.vue` — **collapsed mode** (zwijanie do 64px, same ikony + tooltipy), stan w `localStorage` (`crafter.sidebarCollapsed`), animacje `v-auto-animate`.
- **Argo Task** zawsze zwinięte na starcie. Pozycje PIM zebrane w grupę **„Argo PIM"** (tylko trasy faktycznie istniejące w `routes/crafter.php` — reszta świadomie pominięta, by nie wywalić menu przez Ziggy).
- Układ menu: Dashboard → Argo HQ → Argo PIM → Argo Connect → Argo Task → AI Tools → Users.
- **TODO:** Theme Settings (Spatie) + dynamiczna szerokość z panelu — backend nieskonfigurowany.

### 4.4 Deploy na produkcję (2026-05-20/21)
- Wdrożenie ~117 zmienionych plików (4 moduły) na `pim.bsplate.eu` (DirectAdmin).
- Główne problemy i wnioski (pełny rozbiór w `deploy.md`):
  - **Brak gita** → paczki budowane wg daty modyfikacji.
  - **Prod cofnięty do ~14 maja** → paczka różnicowa była niekompletna (brakujące klasy/trasy wywalały menu) → **konieczny pełny sync backendu**.
  - **Brak npm/node na prodzie** → front budować lokalnie i wgrywać `public/build`.
  - Pusty sidebar = brakująca trasa Ziggy (`crafter.integrations.status`) — diagnoza przez konsolę (F12).
  - Symlink `public_html/build → PIM/public/build` — build rozpakowywać do `PIM/public/`.
  - Kolejność: kod → `migrate --force` → import danych → `clear cache` → front.

### 4.5 Sesje 2026-06-08/09 — grid, Argo Mail, matryca tłumaczeń (osobne dzienniki)
- **Grid zamiast Google Sheets (2026-06-08):** edycja cenników (`pricelist_product`) i nadpisań integracji (`integration_products.overrides`) przeniesiona z osadzonych arkuszy Google do natywnego gridu (`DataGrid.vue` / RevoGrid); `GoogleSheetsService` skasowany, trasy sync usunięte; doszły CSV import/eksport, klonowanie cennika, kursy NBP. Szczegóły: `docs/2026-06-08-grid-*.md` (przegląd / cennik / integracje / wdrożenie).
- **Argo Mail:** moduł poczty — architektura i funkcje opisane w `docs/mail/`.
- **Matryca tłumaczeń:** dokumentacja modułu w `docs/matryca-tlumaczen/` (README + 01–07).

### 4.6 Karty robocze full-width (2026-06-10)
- **Każda karta robocza ma 100% szerokości obszaru roboczego.** Usunięto per-stronowe wrappery `max-w-*` + `mx-auto` (22 wystąpienia w 21 plikach `Pages/**`): cenniki, produkty (eksport), kategorie, atrybuty + wartości, źródła, integracje, ustawienia, AI Tools, użytkownicy (formularz/profil/hasło), Mail (SMTP, szablon), Argo Mail (6 zakładek ustawień, formularz skrzynki), Argo Task (zadanie, grupy, projekt), dashboard (`Home.vue`).
- Layout `PageContent` już wcześniej był domyślnie fluid — winne były tylko wrappery w stronach. Modale, dropdowny, panele boczne i komórki tabel celowo zostały zwężone.
- **Zasada:** nowe strony bez `max-w-*`/`mx-auto` na wrapperze głównej zawartości; ewentualne zwężenie przez `:fluid="false"` na `PageContent`.
- Build OK (`✓ 20.73s`); zmiana front-only — na prod wgrać świeży `public/build`. Szczegóły: `docs/2026-06-10-fullwidth-karty-robocze.md`.

---

## 5. Stan bazy (kluczowe tabele utworzone w ostatnich pracach)

`cost_planner_*`, `bank_statement_*`, `odyssey_cost_*`, `summary_months`, `connect_invoices`, `geo_postal_codes`, `integration_*` (sync_logs, categories, entity_states, media_queue, analytics, connector_runs, blog_mappings).

---

## 6. Otwarte tematy / TODO

- **Git** — założyć repo (koniec z pakowaniem po dacie modyfikacji). Główny wniosek z deployu.
- **Filtr źródeł Zestawień** (`ALLOWED_SOURCES`) — po pierwszej synchronizacji BSP ustawić **dokładny** `order_source` (dziś realnie łapie tylko `ebay`).
- **Eksport XLS** — kolumny Betrag / S/H / Gegenkonto / Konto puste, do uzupełnienia regułami księgowania.
- **Klucze API BaseLinker** szyfrowane `APP_KEY` — jeśli prod ma inny klucz, wpisać od nowa w `/admin/connect/integrations/base`.
- **Cron schedulera** na prodzie: `* * * * * php artisan schedule:run` (sync zamówień co 5 min, faktur co 15 min).
- **Moduł Blog** — uśpiony (Future-Proof); po powstaniu odkomentować 4 punkty + dodać FK `blog_id → blogs.id`.
- **Theme Settings** sidebara — backend (Spatie settings + migracja + tab) nieskonfigurowany.
- **12 migracji `integration_*`** (batch 25) uruchomionych mimochodem — przeszły czysto; rozważyć, czy zostają.
- Kolumna `invoice_number` w `cost_planner_items` — nieużywana, można usunąć osobną migracją.

---

## 7. Szczegółowe dzienniki (gdzie szukać)

| Dokument | Zakres |
|---|---|
| [`deploy.md`](deploy.md) | Dziennik wdrożenia na produkcję (2026-05-20/21) — problemy, przyczyny, poprawna procedura |
| [`SIDEBAR-REDESIGN-WDROZENIE.md`](SIDEBAR-REDESIGN-WDROZENIE.md) | Redesign sidebara + collapsed mode |
| [`docs/2026-05-20-Argo-HQ-Zestawienia-i-zmiany.md`](docs/2026-05-20-Argo-HQ-Zestawienia-i-zmiany.md) | Argo HQ: Zestawienia, eksport XLS, zmiany w zamówieniach |
| [`+importy/WDROZENIE-connectors-2026-05-20.md`](+importy/WDROZENIE-connectors-2026-05-20.md) | Wdrożenie konektorów + dział Integracje |
| [`+importy/argo-connect/argo-connect-MASTER.md`](+importy/argo-connect/argo-connect-MASTER.md) | Master Argo Connect (BaseLinker) |
| [`sumpguard-analiza.md`](sumpguard-analiza.md) | Analiza Sumpguard (Source Importer atrybutów) |
| [`docs/2026-06-08-grid-przeglad.md`](docs/2026-06-08-grid-przeglad.md) (+ `-cennik`, `-integracje`, `-wdrozenie`) | Migracja Google Sheets → natywny grid (cenniki + nadpisania integracji) |
| [`docs/mail/`](docs/mail/) | Moduł Argo Mail + Mail SMTP (architektura, funkcje, makiety) |
| [`docs/matryca-tlumaczen/`](docs/matryca-tlumaczen/README.md) | Matryca tłumaczeń (architektura, komendy, observers, UI, rollout) |
| [`docs/2026-06-10-fullwidth-karty-robocze.md`](docs/2026-06-10-fullwidth-karty-robocze.md) | Karty robocze full-width — usunięcie `max-w-*` z 21 stron, zasada na przyszłość |

---

*Dokument zbiorczy. Stan na 2026-06-10. Źródło prawdy: migracje (`database/migrations/`), kontrolery (`app/Http/Controllers/`) i powyższe dzienniki.*
