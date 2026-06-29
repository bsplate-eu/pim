# KSeF — moduł e-Faktur (pełna dokumentacja + instalacja)

Moduł integracji z **Krajowym Systemem e-Faktur (KSeF 2.0)** w PIM (Laravel 10 + Inertia + Vue 3, „crafter").
Zaciąga realne faktury z KSeF, prezentuje je per firma w stylu „Planera kosztów", trzyma poświadczenia per firma,
synchronizuje się sam (delta co 15 min + dzienna siatka). Dokument = jedyne źródło prawdy o module.

Data: 2026-06-26. Firmy: **Pareto** (NIP 9252014791), **BSP / Black Steel Plate** (NIP 9252152027). Środowisko: **prod**.

---

## 1. Co robi moduł

- **Argo HQ → Ksef → „Ksef Pareto" / „Ksef BSP"** — lista faktur danej firmy (Data, Kategoria, Nr FV, Kontrahent,
  Pozycja FV, Termin, Dni, Kwota, Opłacone, PDF) + filtry (Miesiąc/Kwartał/Rok/Status) + „Import faktur" + zakładka
  **Ustawienia** (CRUD kategorii). UX wzorowany na `CostPlanner/Show.vue`.
- **Argo Connect → „Integracje · KSEF"** — poświadczenia integracji (NIP, środowisko, token KSeF) w 2 tabach (Pareto | BSP).
- **Pobieranie danych** — realne faktury z KSeF 2.0 przez **eksport zbiorczy** (async): pełne XML-e → pozycje, terminy,
  strony, kwoty. Plus pojedyncze pobranie XML pod **PDF**.
- **Automatyka** — komenda CLI `ksef:import` + harmonogram: **delta co 15 min** (tylko nowe rejestracje) i **23:59**
  (pełna siatka).

Status płatności („Opłacone") i kategorie prowadzimy **u siebie** — KSeF ich nie zna; import ich NIE nadpisuje.

---

## 2. Architektura (dlaczego tak)

- **SDK:** `n1ebieski/ksef-php-client` (KSeF 2.0, PHP 8.1+, produkcyjny od 04.2026). Obsługuje całe crypto/auth/JWT/AES.
- **Auth:** token KSeF (challenge → szyfrowanie tokenu kluczem publicznym MF → access/refresh JWT). Tokeny działają do
  końca 2026 (potem certyfikaty). Budowane raz na cykl (cache w obiekcie `KsefClient`).
- **Pobieranie masowe = EKSPORT ZBIORCZY**, NIE pojedyncze pobrania w pętli. Powód: KSeF PROD **rate-limituje** pojedyncze
  `invoices/download` → **429 Too Many Requests po ~16 szybkich**. Eksport (`invoices/exports`) zwraca paczkę ZIP ze
  wszystkimi XML-ami w jednej operacji async → ~275 FV / 3 mies. w ~12 s, bez limitu.
- **Okna dat:** KSeF wymaga zakresu **< 3 miesiące** w 1 zapytaniu → `KsefClient::monthlyWindows()` dzieli na okna ~miesięczne.
- **dateType:** `Issue` = data wystawienia (backfill); `Invoicing` = data rejestracji w KSeF (delta przyrostowa).
- **subjectType:** `Subject2` = firma jako NABYWCA → **zakupowe/kosztowe** (domyślne); `Subject1` = SPRZEDAWCA → **sprzedażowe**.
- **Web vs CLI:** masowy import w web-requeście **timeoutuje** na shared hostingu (eksport jest wolny/async) → masowo
  zawsze leci **CLI** (`artisan ksef:import`) / cron. Przycisk „Import faktur" w panelu = tylko małe zakresy (1 mc).
- **PDF:** generowany z **zapisanego XML** (kolumna `ksef_invoices.xml`) — bez odpytywania KSeF na klik (inaczej 429).
  Render własny (`buildPdf`, poprawny PDF, PL→ASCII). Oficjalna wizualizacja KSeF 1:1 wymaga osobnego generatora Node
  (`n1ebieski/ksef-pdf-generator`) — NIE wpięte.

---

## 3. Korelacje z resztą aplikacji

- **Sidebar** (`resources/js/crafter/Components/Sidebar.vue`):
  - grupa **Argo HQ** → podgrupa **Ksef** (pozycje: Ksef Pareto, Ksef BSP);
  - grupa **Argo Connect** → pozycja **Integracje · KSEF** (obok Base/Ebay).
- **Strona eBay** (`Connect/Integrations/Ebay/Index.vue`) — do górnego paska zakładek (BaseLinker | Ebay) dołożono **KSeF**,
  żeby nawigacja między integracjami działała w obie strony.
- **Scheduler** (`app/Console/Kernel.php`) — wpisy KSeF żyją obok `baselinker:*`, `mail:sync`, `scope:*`. Wymagają tego samego
  systemowego crona `schedule:run` (już ustawiony na prodzie).
- **Szyfrowanie:** token KSeF szyfrowany `APP_KEY` (jak `client_secret` eBay / `api_key` BaseLinker). **Inny APP_KEY na prodzie
  ⇒ token z lokalu się nie odszyfruje** → wpisać na prodzie od nowa.
- **Stack front:** Inertia + Vue 3 + Ziggy (`route()`), komponenty `crafter/Components` (PageHeader, PageContent, Card,
  Button, Modal, Toggle). Build przez Vite (Node 18). KSeF używa **zwykłej tabeli HTML**, NIE DataGrid/RevoGrid.
- **Composer/vendor:** SDK podbił `phpseclib` 3.0.46 → **3.0.55** (patch) — dotyczy całego projektu (używają go też inne paczki),
  ale to bezpieczny bump.

---

## 4. Baza danych (3 tabele)

Migracje `database/migrations/2026_06_26_*` (lub `_deploy_ksef_2026-06-26.sql` do phpMyAdmin).

### `ksef_settings` — poświadczenia per firma
| kolumna | typ | uwagi |
|---|---|---|
| company | varchar UNIQUE | 'pareto' \| 'bsp' |
| label | varchar | nazwa wyświetlana |
| nip | varchar null | NIP firmy |
| environment | varchar(16) | 'test' \| 'prod' |
| auth_token | text null | **szyfrowany (Crypt/APP_KEY)** |
| enabled | bool | |
| last_sync_at | timestamp null | znacznik delty (`--since`) |
| timestamps | | |

### `ksef_invoices` — faktury
| kolumna | typ | uwagi |
|---|---|---|
| company | varchar INDEX | |
| issue_date | date | Data wystawienia (P_1) |
| number | varchar | Nr FV (P_2) |
| contractor | varchar null | sprzedawca (zakup) / nabywca (sprzedaż) |
| items_text | text null | pozycje (złączone P_7) |
| category | varchar null | NASZA, edytowalna |
| due_date | date null | termin płatności (z XML) |
| amount | decimal(12,2) | brutto (P_15) |
| currency | varchar(8) | |
| status | varchar(16) | 'paid' \| 'unpaid' — NASZE |
| ksef_ref | varchar null | **numer KSeF (klucz deduplikacji)** |
| pdf_path | varchar null | (rezerwa) |
| xml | longtext null | pełny dokument — pod PDF, **hidden** |
| source | varchar(16) | 'ksef' \| 'demo' |
| imported_at | timestamp null | |
| timestamps | | |
| **UNIQUE** | (company, ksef_ref) | NIE (company, number) — różni sprzedawcy mogą mieć ten sam nr |

### `ksef_categories` — kategorie per firma
company INDEX, name, position, timestamps, **UNIQUE (company, name)**. Seed: Sprzedaż, Usługi, Towary, Transport, Inne ×2 firmy.

---

## 5. Trasy (`routes/crafter.php`)

**Argo HQ — KSeF** (grupa Argo HQ):
```
GET    admin/ksef/pareto                         crafter.ksef.pareto
GET    admin/ksef/bsp                            crafter.ksef.bsp
POST   admin/ksef/{company}/import               crafter.ksef.import           (company: pareto|bsp)
PATCH  admin/ksef/invoices/{ksefInvoice}/category crafter.ksef.invoices.category
PATCH  admin/ksef/invoices/{ksefInvoice}/status   crafter.ksef.invoices.status
GET    admin/ksef/invoices/{ksefInvoice}/pdf      crafter.ksef.invoices.pdf
POST   admin/ksef/{company}/categories           crafter.ksef.categories.store
PATCH  admin/ksef/categories/{ksefCategory}      crafter.ksef.categories.update
DELETE admin/ksef/categories/{ksefCategory}      crafter.ksef.categories.destroy
```

**Argo Connect — Integracje · KSeF** (osobny blok):
```
GET    admin/connect/integrations/ksef           crafter.connect.integrations.ksef.index
PUT    admin/connect/integrations/ksef           crafter.connect.integrations.ksef.update
```

---

## 6. Backend — pliki i odpowiedzialności

- **`app/Http/Controllers/Admin/KsefController.php`** — strony HQ. `pareto()/bsp()→show()` (lista + filtry rok/mc/kwartał/status),
  `updateCategory()` (kategoria FV), `updateStatus()` (opłacone), `storeCategory()/updateCategoryName()/destroyCategory()`
  (CRUD kategorii — rename przepisuje też `category` na fakturach), `import()` (eksport zbiorczy → upsert po ksef_ref;
  nie nadpisuje status/kategorii), `pdf()` (z zapisanego XML → `pdfLines()`+`buildPdf()`+`pl2ascii()`), `buildDateRange()`.
- **`app/Http/Controllers/Admin/Connect/IntegrationKsefController.php`** — `index()` (firstOrCreate pareto+bsp, maskowany token),
  `update()` (zapis NIP/środowisko/token/aktywna per firma).
- **`app/Models/Ksef/KsefSettings.php`** — token szyfrowany (set/get accessor `Crypt`), `hasToken()`, `maskedToken()`,
  cast `last_sync_at`/`enabled`, `$hidden=['auth_token']`.
- **`app/Models/Ksef/KsefInvoice.php`** — `$hidden=['xml']`, casty dat/`amount`, `isPaid()`.
- **`app/Models/Ksef/KsefCategory.php`** — company/name/position.
- **`app/Services/Ksef/KsefClient.php`** — owijka SDK. `exportInvoices(from,to,subjectType='Subject2',dateType='Issue')`
  (init→poll→deszyfr→ZIP→parse; nazwa pliku=numer KSeF=`ksef_ref`; zapisuje też pełny `xml`), `downloadInvoiceXml(ksefNumber)`
  (pojedyncze, throttle+backoff na 429 — fallback PDF), `client()` (auth + `withEncryptionKey` cache), `monthlyWindows()`.
- **`app/Services/Ksef/KsefInvoiceParser.php`** — `parse(xml)` (xpath po `local-name()`): pozycje `P_7`, termin
  `TerminPlatnosci/Termin`, strony `Podmiot1/2`, `P_1`(data), `P_2`(nr), `P_15`(brutto), `KodWaluty`.
- **`app/Console/Commands/KsefImport.php`** — komenda `ksef:import` (patrz §9).

---

## 7. Frontend — pliki

- **`resources/js/crafter/Pages/Ksef/Index.vue`** — strona faktur. Zakładki **Faktury** / **Ustawienia**. Filtry
  Miesiąc→Kwartał→Rok→**Status (toggle: Zapłacone/Niezapłacone/Wszystkie)**→**X Wyczyść**. Kolumny: Data / Kategoria(edycja) /
  Nr FV / Kontrahent / **Pozycja FV**(tooltip po hover) / Termin / **Dni**(opłacone → „Zapłacone" zielone) / Kwota /
  **Opłacone**(checkbox) / **PDF**. Podsumowanie liczone NA ŻYWO (`liveSummary`), `rows` syncowane z propsów (`watch`).
  **Import faktur** = modal (Miesiąc/Kwartał/Rok + „Zaciągnij wszystko"). Ustawienia = CRUD kategorii.
- **`resources/js/crafter/Pages/Connect/Integrations/Ksef/Index.vue`** — poświadczenia, górny pasek (BaseLinker|Ebay|KSeF) +
  2 taby firm (Pareto|BSP): NIP, środowisko, token (pokaż/ukryj, maskowanie), toggle aktywna, zapis.
- **`resources/js/crafter/Components/Sidebar.vue`** *(modyfikacja)* — grupa Ksef w Argo HQ + Integracje · KSEF w Connect.
- **`resources/js/crafter/Pages/Connect/Integrations/Ebay/Index.vue`** *(modyfikacja)* — zakładka KSeF w górnym pasku.

Build: `node node_modules/vite/bin/vite.js build` (Node 18, brak npm na prodzie → build lokalnie, wgrać `public/build`).

---

## 8. Przepływ integracji KSeF

1. **Auth** (`KsefClient::client()`): `ClientBuilder` → `withMode(Production|Test)` + `withKsefToken` + `withIdentifier(NIP)` +
   `withEncryptionKey(EncryptionKeyFactory::makeRandom())` → `build()` (challenge, szyfrowanie tokenu, status, redeem JWT).
2. **Eksport** (masowo): `invoices()->exports()->init(filters{subjectType,dateRange{dateType,from,to}}, onlyMetadata:false)`
   → poll `exports()->status` aż `code 200` → `package.parts[].url` → `file_get_contents` → `DecryptDocumentAction`
   (AES, ten sam encryptionKey) → sklejony ZIP → unzip → XML-e → `KsefInvoiceParser::parse`.
3. **Download** (PDF, pojedynczo): `invoices()->download(KsefNumber)` → XML (plaintext) → parse → `buildPdf`.
4. **Limity:** okna < 3 mies. (`monthlyWindows`), 429 na pojedynczych pobraniach (throttle+backoff), paczka może mieć
   wiele części (`parts`, sort po `ordinalNumber`).

---

## 9. CLI + harmonogram (sync na prodzie)

Komenda **`php artisan ksef:import`** (`app/Console/Commands/KsefImport.php`):
```
{company?}            pareto|bsp (puste = obie z tokenem)
--from=YYYY-MM-DD     data od (backfill)
--to=YYYY-MM-DD       data do (domyślnie dziś)
--days=45             ostatnie N dni, gdy brak --from
--since               tryb przyrostowy: tylko nowe rejestracje od last_sync_at (po dacie Invoicing), bufor -1h, przesuwa znacznik
--type=Subject2       Subject2=zakupowe (domyślne), Subject1=sprzedażowe
```
Przykłady:
```
php artisan ksef:import pareto --from=2026-01-01     # backfill całego roku (Pareto, zakupowe)
php artisan ksef:import --since                      # delta obu firm (cron)
php artisan ksef:import bsp --type=Subject1 --from=2026-01-01   # BSP sprzedażowe
```

**Harmonogram** (`app/Console/Kernel.php`, przez istniejący cron `schedule:run`):
```
*/15 * * * *   ksef:import --since     (delta — tylko nowe, ~5s/kilka FV; lekkie)
59   23 * * *   ksef:import            (siatka — pełny --days=45 po dacie wystawienia)
```
Idempotentne (`firstOrNew` po `ksef_ref`), `withoutOverlapping()`, nie nadpisuje status/kategorii.

> Dlaczego CLI: na shared hostingu masowy import w panelu timeoutuje (w panelu łapało 1 FV). CLI nie ma limitu czasu.

---

## 10. Zależności composer (vendor)

`composer require n1ebieski/ksef-php-client -W` dociąga (prod NIE ma composera → wgrać `vendor/` z paczki deploy):
`n1ebieski/ksef-php-client`, `endroid/qr-code`, `bacon/bacon-qr-code`, `cuyz/valinor`, `krowinski/bcmath-extended`,
`psr-discovery/*`, **`phpseclib/phpseclib` (upgrade 3.0.46→3.0.55)**, `paragonie/constant_time_encoding` (upgrade)
+ regenerowany `vendor/composer/` (autoloader). `php-http/discovery` był już w projekcie.

Wymagane rozszerzenia PHP (8.3): `zip, openssl, bcmath, gmp, simplexml, mbstring, curl` (na prodzie są).

---

## 11. Instalacja (świeża / prod)

> Paczki: `_deploy_ksef_2026-06-26.zip` (kod+vendor+`public/build`) · `_deploy_ksef_2026-06-26.sql` (schemat) ·
> `_deploy_ksef_cron_2026-06-26.zip` (komenda+Kernel). Szczegóły i rollback: `DEPLOY-KSEF-2026-06-26.md`.

1. **Backup** bazy + (`routes/crafter.php`, `vendor/composer`, `vendor/phpseclib`, `public/build`).
2. **Baza:** import `_deploy_ksef_2026-06-26.sql` w phpMyAdmin (albo `php artisan migrate --force`).
3. **Pliki:** `unzip -o -d $PIM $PIM/_deploy_ksef_2026-06-26.zip` (rozpakuje też `vendor/` i `public/build`).
4. **Cron/komenda:** `unzip -o -d $PIM $PIM/_deploy_ksef_cron_2026-06-26.zip`.
5. **Cache:** `php artisan route:clear config:clear view:clear cache:clear`.
6. **Token:** panel → Argo Connect → Integracje · KSEF → wpisz NIP + środowisko **produkcja** + token (Pareto i BSP).
7. **Backfill:** `php artisan ksef:import pareto --from=2026-01-01` (+ bsp wg potrzeby).
8. **Cron:** upewnij się, że `crontab -l | grep schedule:run` istnieje (delta i siatka ruszą same).

Pełna ścieżka php prod: `/usr/local/php83/bin/php`; root: `/home/admin/domains/pim.bsplate.eu/PIM`; `public_html/build` → symlink do `PIM/public/build`.

---

## 12. Decyzje / konfiguracja

- **Domyślnie zakupowe (Subject2)** — model „Planera kosztów" (do zapłaty, kontrahent=sprzedawca). Komenda przyjmuje `--type`.
- **Per firma** — Pareto (handlowiec) = zakupowe ma sens; **BSP/Black Steel Plate to sprzedawca** → zakupowych ma ~1, jego
  faktury to głównie **sprzedaż** (`--type=Subject1`). Docelowo: kolumna `subject_type` w `ksef_settings` per firma + osobna delta.
- **Demo → live** — pierwotnie był tryb demo (przykładowe FV); zastąpiony realnym eksportem. `source` rozróżnia `'demo'`/`'ksef'`.

---

## 13. Ograniczenia / TODO

- **PDF** = uproszczony render z danych faktury (nie oficjalna wizualizacja KSeF 1:1 — ta wymaga generatora Node
  `n1ebieski/ksef-pdf-generator`).
- **Sprzedaż/zakup per firma** — dziś globalne `--type`; do dorobienia per firma (BSP=sprzedaż).
- **Przełącznik Zakup/Sprzedaż** w modalu importu — kontroler przyjmuje `subject_type`, front jeszcze niewpięty.
- **Termin płatności** jest w ~58% FV (reszta nie ma `TerminPlatnosci` w dokumencie — normalne).
- **Cache tokena sesji** (access/refresh w `ksef_settings`) — opcjonalna dalsza optymalizacja delty.

---

## 14. Wszystkie pliki modułu

**Utworzone:**
```
app/Http/Controllers/Admin/KsefController.php
app/Http/Controllers/Admin/Connect/IntegrationKsefController.php
app/Models/Ksef/KsefSettings.php
app/Models/Ksef/KsefInvoice.php
app/Models/Ksef/KsefCategory.php
app/Services/Ksef/KsefClient.php
app/Services/Ksef/KsefInvoiceParser.php
app/Console/Commands/KsefImport.php
resources/js/crafter/Pages/Ksef/Index.vue
resources/js/crafter/Pages/Connect/Integrations/Ksef/Index.vue
database/migrations/2026_06_26_120000_create_ksef_settings_table.php
database/migrations/2026_06_26_130000_create_ksef_invoices_table.php
database/migrations/2026_06_26_140000_create_ksef_categories_table.php
database/migrations/2026_06_26_150000_ksef_invoices_dedup_by_ksef_ref.php
database/migrations/2026_06_26_160000_add_xml_to_ksef_invoices.php
```
**Zmodyfikowane:**
```
routes/crafter.php                                         (+ trasy KSeF)
app/Console/Kernel.php                                     (+ harmonogram delta/siatka)
resources/js/crafter/Components/Sidebar.vue                (+ grupa Ksef / Integracje · KSEF)
resources/js/crafter/Pages/Connect/Integrations/Ebay/Index.vue  (+ zakładka KSeF)
composer.json / composer.lock                             (+ n1ebieski/ksef-php-client)
```
**Generowane (do prod):** `vendor/` (paczki composer + autoloader) · `public/build/` (skompilowany front).

---

## 15. Diagnostyka

| Objaw | Przyczyna / fix |
|---|---|
| `429 Too Many Requests` | Limit pojedynczych pobrań → używaj eksportu (jest); przy bardzo szybkim klikaniu PDF — chwila przerwy. |
| Panel łapie 1 fakturę / „nie zaciąga" | Timeout web-requestu (shared hosting) → import masowo **z CLI** (`ksef:import`), nie z przycisku. |
| `BLAD: ... Date range ... less than 3 months` | Zakres > 3 mies. w 1 zapytaniu — `monthlyWindows` to dzieli; jeśli ręcznie — zawęź. |
| Pozycja FV / termin puste | Pochodzą z pełnego XML (eksport), nie z metadanych — upewnij się że szło `exportInvoices`, a nie sama metadana. |
| Token „zapisany=NIE" / `env=test` | Token nie zapisany / złe środowisko → wpisz w panelu (env=produkcja). |
| Pusty sidebar | Brakująca trasa (Ziggy, F12) → sprawdź `routes/crafter.php` + `route:clear`. |
| `Class not found` n1ebieski/phpseclib | Niepełny `vendor/` lub stary autoloader → ponów rozpakowanie vendora + `optimize:clear`. |
| BSP ma ~1 fakturę | Subject2=zakupowe, a BSP to sprzedawca → użyj `--type=Subject1`. |

Diagnostyka na prodzie (wkleić output):
```bash
P=/usr/local/php83/bin/php; PIM=/home/admin/domains/pim.bsplate.eu/PIM
$P -m | grep -iE "^(zip|openssl|bcmath|gmp|simplexml)$"
$P $PIM/artisan schedule:list | grep -i ksef
$P $PIM/artisan ksef:import pareto --days=10        # test realnego pobrania
```
