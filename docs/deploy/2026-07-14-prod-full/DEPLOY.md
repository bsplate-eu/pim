# DEPLOY PROD pim.bsplate.eu — paczka zbiorcza 2026-07-14

Zawartosc: kod integracji (5 plikow) + 4 skrypty naprawcze + szablon Selly v3.
Baza: ZERO zmian schematu (bez migracji). Weryfikacja: osobny plik
_weryfikacja_prod_2026-07-14.sql (read-only SELECT-y do phpMyAdmin).

## KROK 0 — backup
Skopiuj obok oryginaly 5 plikow kodu:
  app/Services/Integration/SyncService.php
  app/Services/Integration/Pipelines/CatalogCreatePipeline.php
  app/Services/Integration/Pipelines/CatalogDeltaPipeline.php
  app/Services/Integration/Pipelines/MediaSyncPipeline.php
  app/Models/IntegrationProduct.php

## KROK 1 — rozpakowanie (root PIM)
cd ~/domains/pim.bsplate.eu/PIM
unzip -o _deploy_PROD_FULL_2026-07-14.zip
# nadpisze 5 plikow kodu + wgra skrypty _*.php do roota

## KROK 2 — cache
/usr/local/php83/bin/php artisan optimize:clear
# UWAGA: odslania ukryte parse errory (route cache maskuje) — jesli cos krzyknie, STOP i backup wraca

## KROK 3 — skrypty naprawcze (w tej kolejnosci; wszystkie idempotentne)
/usr/local/php83/bin/php _fix_locales.php          # krzaki w 8 locale (bg/en/et/hu/it/lt/lv/ro)
/usr/local/php83/bin/php _fix_pl_form.php          # forma PL: "Stalowa oslona silnika X"
/usr/local/php83/bin/php _fix_de.php               # DRY-RUN: DE zamrozone po polsku (lock auto_matrix)
/usr/local/php83/bin/php _fix_de.php --apply       # zapis (jesli dry-run wyglada dobrze)
/usr/local/php83/bin/php _add_material_attribute.php   # atrybut Material Stal/Alu (14 locale)
# Jesli ktorys byl juz odpalony wczesniej — drugi run pokaze 0 zmian (bezpieczne).

## KROK 4 — Selly (panel)
Wklej zawartosc _selly_szablon_v3_material.html jako szablon opisu w panelu Selly
(v3 z blokiem @if($is_alu) — material Stal/Alu).

## KROK 5 — sync do sklepow
Delta sync (cron lub recznie). Hash kazdego produktu sie zmieni (doszedl
short_description) -> krotkie opisy wejda do ps_product_lang.description_short
(PrestaShop: bsplate.de/sk/cz). OpenCart ignoruje short desc (nie ma pola).
KOLEJNOSC MA ZNACZENIE: skrypty nazw PRZED syncem -> sklep dostanie poprawione
nazwy + short desc w jednym przebiegu.

## KROK 6 — weryfikacja
Odpal SELECT-y z _weryfikacja_prod_2026-07-14.sql w phpMyAdmin (PIM + sklep).
W PIM admin: Ctrl+Shift+R.

## Co niesie kod (5 plikow)
- short_description do payloadu (bylo twarde null) — commit badeeb9
- yearSuffix: roczniki (year-start/stop) w nazwie w kazdym locale — 21028f9
- media: pim_id w payloadzie (connector OpenCart) — a897260
- IntegrationProduct: override name:null NIE zeruje nazwy (incydent 2026-07-02) — c0793b7

## Uwagi
- NIE przepakowywac przez PowerShell Compress-Archive (psuje separatory na Linux).
- Produkt bez template-short i bez info_2: payload wysle {} -> delta wyczysci
  description_short (analogicznie jak dziala description). Szablony bsplate renderuja short kazdemu.