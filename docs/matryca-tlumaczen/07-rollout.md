# 07 — Rollout na produkcję + checklist

> Produkcja PIM: `/home/admin/domains/pim.bsplate.eu/PIM/` · PHP CLI `/usr/local/php83/bin/php`
> (szczegóły: pamięć `pim-prod-infra`).

## Kolejność wdrożenia

### 1. Deploy kodu
Wgraj nowe pliki (migracje, modele, observerzy, komendy, kontrolery, service, zmiany w `SumpguardSource`,
`AppServiceProvider`, `routes/crafter.php`) + zbudowany front (`public/build/`).

### 2. Migracje
```bash
/usr/local/php83/bin/php artisan migrate
```
Powstają: `translation_phrases`, `translation_phrase_renditions`, `translation_overrides`.

### 3. Wgraj arkusz
Skopiuj `Dane do matrycowania.xlsx` → `storage/app/translations/source-sheet.xlsx`.

### 4. Import (NAJPIERW dry-run!)
```bash
/usr/local/php83/bin/php artisan translations:import-from-sheet --dry-run
/usr/local/php83/bin/php artisan translations:import-from-sheet
```

### 5. Naprawa kodowania
```bash
/usr/local/php83/bin/php artisan translations:repair-encoding --dry-run
/usr/local/php83/bin/php artisan translations:repair-encoding
/usr/local/php83/bin/php artisan translations:repair-encoding --clear-non-matrix
```

### 6. Dotłumaczenie reszty z matrycy
```bash
/usr/local/php83/bin/php artisan translations:auto-translate --missing-only --dry-run
/usr/local/php83/bin/php artisan translations:auto-translate --missing-only
```

### 7. Weryfikacja
- Wejdź `/admin/translation-phrases` — czy 537 fraz widać
- Wejdź `/admin/translation-review` — przejrzyj niedopasowane
- Sprawdź kilka produktów: `name->de` ≠ polski, znaki czyste

### 8. Test ochrony (KLUCZOWE)
Po pełnym sync Sumpguard (lub ręcznie `sources:sync`) sprawdź, że żadne tłumaczenie z arkusza
nie zostało nadpisane:
```bash
/usr/local/php83/bin/php artisan tinker --execute="\$p=App\Models\Product::find(1114); echo \$p->getTranslation('name','de');"
# powinno zostać "Stahl Unterfahrschutz für Motor Alfa Romeo Mito"
```

---

## Checklist „czy działa"

- [ ] Migracje przeszły (3 tabele istnieją)
- [ ] `translation_phrases` > 0 (matryca zbudowana)
- [ ] `translation_overrides` source=`sheet_import` istnieją (ochrona aktywna)
- [ ] Produkt z arkusza ma czyste `name->de` (nie polski, nie `??`)
- [ ] Po sync Sumpguard `name->de` NIE zmieniło się
- [ ] Nowy produkt z importu dostaje tłumaczenia ze sklejacza (lub ląduje w review)
- [ ] UI matrycy + review queue otwierają się bez błędu
- [ ] Sidebar pokazuje 2 nowe pozycje

---

## Uwaga: synchronizacja aliasów marek

Stałe `BRAND_ALIASES` są **zduplikowane** w 3 plikach:
- `app/Console/Commands/TranslationsExploreSheet.php`
- `app/Console/Commands/TranslationsImportFromSheet.php`
- `app/Services/ProductTranslationComposer.php`

Dodając nowy alias (np. `Citroen↔Citroën`) zmień we wszystkich trzech.
> TODO refactor: wyciągnąć do jednego configu/serwisu.

---

## Znane ograniczenia / dług techniczny

1. **69 produktów z arkusza nie istnieje w PIM** — pominięte przy imporcie (`skipped_no_product`).
2. **~134 produkty bez dopasowania frazy** — „Aluminium" vs „Aluminiowa", „Rav4" vs „RAV 4" itd.
   → review queue + ręczne dopisanie fraz.
3. **`attribute_values` z `?` (77)** — osobna naprawa (patrz [06](06-naprawa-kodowania.md)).
4. **BRAND_ALIASES zduplikowane** w 3 plikach.
5. **Matryca pokrywa tylko 6 locale + 5 Allegro** — en/lt/it/lv/et/ro/hu/bg nieobsługiwane
   (świadomie — to języki nietłumaczone).
