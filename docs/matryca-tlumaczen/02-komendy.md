# 02 — Komendy artisan

> Lokalnie PHP musi być **8.3** (vendor wymaga ≥8.2):
> `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan ...`
> Domyślne `php` w PATH (7.4) ubija artisan na platform-check.

---

## `translations:explore-sheet`
**Analiza arkusza BEZ zmian w bazie.** Pokazuje jaką matrycę wyekstrahuje.

```bash
php artisan translations:explore-sheet
php artisan translations:explore-sheet --limit=50          # tylko 50 wierszy
php artisan translations:explore-sheet --out=storage/app/translations/proposed-matrix.json
```
Wejście: `storage/app/translations/source-sheet.xlsx` (kopia „Dane do matrycowania.xlsx").
Wyjście: JSON + raport (TOP frazy, pokrycie per kanał, niespójności).

Plik: `app/Console/Commands/TranslationsExploreSheet.php`

---

## `translations:import-from-sheet`
**Import właściwy** — wpisuje tłumaczenia do bazy + buduje matrycę.

```bash
php artisan translations:import-from-sheet --dry-run        # podgląd planu
php artisan translations:import-from-sheet --dry-run --limit=3
php artisan translations:import-from-sheet                  # pełny import
php artisan translations:import-from-sheet --no-matrix      # bez budowy matrycy (tylko per-produkt)
```

Co robi (idempotentnie):
1. `products.name` ← kolumny 4/10/11/12/13/14 (PL/DE/CS/SK/FR/ES)
2. `integration_products.overrides.name` ← kolumny 5-9 (5 kont Allegro) + alias int 12
3. Każdy zapisany slot → wpis `translation_overrides` source=`sheet_import` (lock)
4. `translation_phrases` + `renditions` (top-vote per kanał)

Plik: `app/Console/Commands/TranslationsImportFromSheet.php`

> ⚠️ Włącza `TranslationOverride::$suppressObserver = true` na czas importu —
> żeby programatyczne zapisy NIE oflagowały się jako `manual`.

---

## `translations:auto-translate`
**Sklejacz bulk** — wypełnia tłumaczenia z matrycy dla istniejących produktów.

```bash
php artisan translations:auto-translate --dry-run                    # ile się dopasuje
php artisan translations:auto-translate --product=1115               # jeden produkt
php artisan translations:auto-translate --missing-only               # tylko bez locka name
php artisan translations:auto-translate --source=SumpguardSource     # całe źródło
php artisan translations:auto-translate --limit=100
```

Pomija sloty już zablokowane (manual/sheet_import/auto_matrix) → idempotentne.
Produkty bez dopasowania frazy zliczane jako `unmatched` (idą do review).

Plik: `app/Console/Commands/TranslationsAutoTranslate.php`

---

## `translations:repair-encoding`
**Naprawa popsutych polskich znaków** (`Os??ona` → `Osłona`). Szczegóły: [06](06-naprawa-kodowania.md).

```bash
# Napraw locale matrycy (pl/de/cs/sk/fr/es) z czystego feedu Sumpguard:
php artisan translations:repair-encoding --dry-run
php artisan translations:repair-encoding
php artisan translations:repair-encoding --locale=pl       # tylko jeden locale

# Wyczyść śmieci w lokalach SPOZA matrycy (en/lt/it/lv/et/ro/hu/bg):
php artisan translations:repair-encoding --clear-non-matrix --dry-run
php artisan translations:repair-encoding --clear-non-matrix
```

Naprawia/czyści TYLKO sloty zawierające `?` i NIE zablokowane.
Źródło prawdy: `storage/app/sumpguard/{locale}.json` (czysty UTF-8).

Plik: `app/Console/Commands/TranslationsRepairEncoding.php`

---

## Tabela komend wg fazy

| Faza | Komenda |
|---|---|
| 1. Rozpoznanie | `translations:explore-sheet` |
| 2. Import danych | `translations:import-from-sheet` |
| 3. Naprawa kodowania | `translations:repair-encoding` (+ `--clear-non-matrix`) |
| 4. Dotłumaczenie reszty | `translations:auto-translate --missing-only` |
| 5. Bieżąco (cron) | automatycznie w `SumpguardSource` |
