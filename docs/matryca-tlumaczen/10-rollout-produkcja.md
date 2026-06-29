# 10 — Rollout na produkcję (2026-06-10) + pułapki

> Produkcja PIM: `/home/admin/domains/pim.bsplate.eu/PIM/`, PHP CLI `/usr/local/php83/bin/php`.
> **Brak gita/composera/npm na prodzie** + **brak klucza SSH po stronie asystenta** → wszystko ręcznie przez paczki,
> komendy odpala user i wkleja output.

---

## Stan produkcji = bardzo przestarzały

Produkcja była cofnięta o DWA wdrożenia, których nigdy nie zrobiono:
1. **Migracja grid** (2026-06-08, [docs/2026-06-08-grid-wdrozenie.md](../2026-06-08-grid-wdrozenie.md)) — Google Sheets → natywny grid. Lokalnie zrobiona, na prod NIE.
2. **Moduł tłumaczeń** (ta sesja).

Konsekwencja: prod siedział na starym kodzie z Google Sheets (martwym — konto Google `invalid_grant: account not found`).

---

## Paczki (Windows → Linux)

`Compress-Archive` PSUJE separatory ścieżek dla Linuksa → używać **`tar`** (zachowuje `/`):
```bash
tar -czf deploy_xxx.tar.gz app/Services/... public/build ...
```

Pliki **WSPÓŁDZIELONE** (mają nie-tłumaczeniowy kod) NIE idą do paczki — nadpisanie skasowałoby cudze zmiany:
- `routes/crafter.php` → dodać linie route **ręcznie** (File Manager)
- `app/Sources/SumpguardSource.php` → hook `composer->apply` (sprawdzić/dodać)
- `app/Providers/AppServiceProvider.php` → observery (sprawdzić/dodać)

Sprawdzenie obecności na prodzie:
```bash
grep approve-bulk routes/crafter.php
grep ProductTranslationComposer app/Sources/SumpguardSource.php
grep TrackingObserver app/Providers/AppServiceProvider.php
```

---

## Matryca → produkcja przez SQL (bez hasła CLI)

Matryca (33 frazy + renditcje) jest **niezależna od produktów** (czyste tłumaczenia typów) → kopiowana z lokalnej.

Generator dumpu (lokalnie, PHP): `matryca_DO_IMPORTU.sql` zawiera **`CREATE TABLE IF NOT EXISTS`** (3 tabele)
+ `TRUNCATE` matrycy + `INSERT`. Dzięki CREATE działa **przez phpMyAdmin Import bez artisan migrate**.
**Nie rusza `translation_overrides`** (ochrona produktów).

> Plik z CREATE = `matryca_DO_IMPORTU.sql` (do phpMyAdmin). Plik bez CREATE = `storage/app/translations/matryca_export.sql` (wymaga gotowych tabel).

---

## Kroki rolloutu (wersja faktyczna)

```bash
cd ~/domains/pim.bsplate.eu/PIM
PHP=/usr/local/php83/bin/php

# 1. backup (mysqldump wymaga hasła → albo phpMyAdmin Export)
cp -r public/build ~/backup_build_$(date +%F)

# 2. rozpakuj paczkę kodu
tar -xzf deploy_tlumaczenia_2026-06-10.tar.gz

# 3. import matrycy → phpMyAdmin: Import matryca_DO_IMPORTU.sql

# 4. współdzielone routy — dodać ręcznie (File Manager) jeśli grep pusto

# 5. wypełnij produkty + zatwierdź
$PHP artisan translations:reclassify --apply   # przelicza product_count, dorabia prod-only frazy
$PHP artisan translations:auto-translate --source=SumpguardSource
$PHP artisan translations:auto-approve

# 6. cache
$PHP artisan optimize:clear
```

Front: po wgraniu `public/build` zawsze **Ctrl+Shift+R** (twardy refresh) — przeglądarka trzyma stary JS.

---

## ⚠️ PUŁAPKI (realne, z tej sesji)

### `optimize:clear` odsłania ukryte parse errory
Prod miał route cache, który **maskował błąd składni** w `IntegrationController.php`. Po `optimize:clear`
Laravel re-parsuje kontrolery → **500 na całej stronie**. Przyczyna: ktoś wcześniej **połowicznie zakomentował**
metodę `editSheet` (Google Sheets) — nagłówek `//`, ciało nie:
```php
//    public function editSheet(...): Response
//    {
        try { $service->createIntegration(...); }   // ← NIE zakomentowane → "unexpected try, expecting function"
```
Fix: `sed -i.bak '266s|^|//|' app/Http/Controllers/Admin/IntegrationController.php` (zakomentować wiszącą linię).
Diagnoza całego kodu: `find app -name "*.php" -exec /usr/local/php83/bin/php -l {} \; 2>&1 | grep -v "No syntax"`.

### Prod nie ma git/composer/npm
`git pull` → `not a git repository`; `composer`/`npm` → `command not found`. Migracja grid „po bożemu"
(git pull + composer install + npm build) **niemożliwa** — trzeba ręcznie wgrać pliki + paczki. Autoload nowych
klas: PSR-4 (`app/`) działa bez `dump-autoload`, chyba że classmap authoritative.

### Google Sheets martwe
Konto Google Service nie istnieje (`invalid_grant`). `sheets:import-final` rzuca `Google\Service\Exception`.
Sheets i tak nie działało — pełne usunięcie = wdrożenie migracji grid (osobny temat, [grid-wdrozenie.md](../2026-06-08-grid-wdrozenie.md)).

### Logi
`storage/logs/laravel.log` może nie istnieć (daily) — szukać `laravel-YYYY-MM-DD.log` albo
`ls -t storage/logs/*.log | head -1 | xargs tail -n 30`.

---

## Stan po rolloucie

Działa: matryca widoczna (34 frazy — 33 z importu + 1 prod-only dorobiona przez reclassify), produkty
wypełnione, review queue z wyszukiwarką/sortowaniem/operacjami masowymi, auto-approve. Hook + observery
do dorobienia (dla AUTOMATU nowych produktów z Sumpguarda) — patrz [03](03-ochrona-i-observers.md), [04](04-sklejacz.md).

Paczki tej sesji: `deploy_tlumaczenia_2026-06-10.tar.gz` (moduł), `deploy_review_ui_2026-06-10.tar.gz` (UI review),
`matryca_DO_IMPORTU.sql` (matryca do phpMyAdmin).
