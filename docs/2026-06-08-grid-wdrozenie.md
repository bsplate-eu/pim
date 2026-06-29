# Wdrożenie gridu na produkcję — krok po kroku

**Data:** 2026-06-08
**Środowisko:** lokalnie wszystko zbudowane i zlintowane, MySQL lokalnie był wyłączony (więc nie odpalono finalnej weryfikacji na bazie). Wdrożenie na prod niżej.

## TL;DR

```bash
# Na prodzie (PIM, /home/admin/domains/pim.bsplate.eu/PIM):
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
/usr/local/php83/bin/php artisan sheets:import-final   # KRYTYCZNE - przed pierwszym wejściem userów w grid
/usr/local/php83/bin/php artisan config:cache
/usr/local/php83/bin/php artisan route:cache
```

## Kolejność operacji (szczegółowo)

### 1. Build + autoload

```bash
cd /home/admin/domains/pim.bsplate.eu/PIM
git pull
composer install --no-dev --optimize-autoloader   # wycina GoogleSheetsService z classmapy
npm ci
npm run build                                      # nowe hashe JS (vendor + index)
```

Wytwarza chunki RevoGrid w `public/build/assets/` (`revo-grid.entry-*.js`, `revogr-data-*.js`, `revogr-clipboard-*.js`, `revogr-attribution-*.js`, `revogr-filter-panel-*.js`).

### 2. Jednorazowy import Google → baza (KRYTYCZNE)

Powód: w starym flow baza była zapisywana DOPIERO przy kliknięciu „Save" w PIM. Jeśli user edytował arkusz Google bez zapisu w PIM, baza jest nieaktualna. Komenda ściąga ostatni stan z arkuszy.

```bash
/usr/local/php83/bin/php artisan sheets:import-final
```

Opcja `--dry-run` najpierw, żeby policzyć:
```bash
/usr/local/php83/bin/php artisan sheets:import-final --dry-run
```

Komenda:
- Przelatuje wszystkie `Pricelist` z `sheet_id != null` — odczytuje arkusz Google, upsert do `pricelist_product` (mapuje `id` → `product_id`, normalizuje cenę).
- Przelatuje wszystkie `Integration` z `sheet_id != null` — odczytuje arkusz, upsert do `integration_products.overrides`.
- Jest **idempotentna** — tylko upsert, nigdy nie kasuje.
- Korzysta z `Sheets::` (revolution/laravel-google-sheets) bezpośrednio, bez `GoogleSheetsService` (więc działa po skasowaniu serwisu).

### 3. Cache cleanup

```bash
/usr/local/php83/bin/php artisan config:cache
/usr/local/php83/bin/php artisan route:cache
/usr/local/php83/bin/php artisan view:clear
```

### 4. Weryfikacja w przeglądarce

1. Otwórz dowolny cennik: `/admin/pricelists/edit/{id}`.
2. Hard refresh (Ctrl+Shift+R).
3. Powinieneś zobaczyć: pola Name + Currency, pasek wyszukiwarki (Kod / Nazwa / Cena od / Cena do), przycisk Operacje masowe, grid z 3 kolumnami + checkbox.
4. Test:
   - Zmień cenę → kliknij Save → sprawdź w bazie `SELECT price FROM pricelist_product WHERE product_id=?`.
   - Eksport CSV → otwórz w Excelu → polskie znaki OK.
   - Aktualizuj z CSV → upload poprzedniego pliku → bez błędu.
5. To samo na integracji: `/admin/integrations/{id}/products`.

## Czego NIE robić

- **Nie zmieniać `APP_KEY`** — pola `Integration.key/url/sheet_id` są `encrypted` (memora `pim-prod-infra` i `connector-new-shop-recipe`). Zmiana wywali deszyfrowanie.
- **Nie usuwać `config/sheets.php`** — komenda importu z pkt 2 i Baselinker raport go używają.
- **Nie usuwać pakietu `revolution/laravel-google-sheets`** — Baselinker dalej go używa (`BaselinkerSheetUpdate.php`).
- **Nie pomijać kroku 2** (import) — bez niego stracimy edycje z arkuszy, które nie były zapisane przez PIM.

## Rollback

Gdyby coś nie zagrało:

```bash
git revert <commit-merge>
composer install --no-dev --optimize-autoloader
npm ci && npm run build
/usr/local/php83/bin/php artisan config:cache
```

Stary flow (iframe Google) wraca. Baza ma już aktualne dane z pkt 2 — nic się nie traci.

## Stare odniesienia do uporządkowania

`paretopim.md` (root projektu) ma wpisy:
- linia 405: `GET /admin/integrations/sync-sheet/{integration} | Synchronizuj arkusz GSheets` — trasa **nie istnieje**, usunąć.
- linia 518: `### GoogleSheetsService` — serwis **skasowany**, usunąć cały blok albo zastąpić odniesieniem do tego docu.

To **kosmetyka**, nie blokuje deploy.

## Konfiguracja środowiska (potwierdzenie)

| Pole | Wartość |
|---|---|
| PHP CLI prod | `/usr/local/php83/bin/php` (8.3) |
| Lokalnie PHP CLI | `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe` |
| Lokalnie Node | `C:\laragon\bin\nodejs\node-v18\node.exe` |
| Ścieżka projektu prod | `/home/admin/domains/pim.bsplate.eu/PIM` |
| Worker queue (`Kernel.php`) | `--queue=sync-catalog,sync-media,sync-blog,sync-analytics,default` (jak wcześniej, nie zmieniam) |

## Co tu nie zostało dotknięte

- Konfiguracja workerów, queue, cron — bez zmian.
- Connector PrestaShop / sklepy zdalne — bez zmian.
- Baselinker raport (`baselinker-sheet:update`) — bez zmian.
