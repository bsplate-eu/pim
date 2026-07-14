-- ============================================================================
-- WERYFIKACJA PROD po deployu paczki _deploy_PROD_FULL_2026-07-14.zip
-- WYŁĄCZNIE SELECT-y (read-only) — nic nie zapisuje, można odpalać wielokrotnie.
--
-- WAŻNE: deploy NIE wymaga ŻADNYCH zmian schematu bazy (zero migracji, zero
-- CREATE/ALTER). Ten plik służy tylko do sprawdzenia efektów w phpMyAdmin.
--
-- Sekcje 1-4: baza PIM (pim.bsplate.eu)
-- Sekcja  5:  baza SKLEPU PrestaShop (bsplate.de / bsplate.sk — osobny phpMyAdmin)
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. DE zamrożone po polsku (przed _fix_de.php: ~122; po --apply: 0)
-- ----------------------------------------------------------------------------
SELECT COUNT(*) AS de_po_polsku
FROM products
WHERE JSON_UNQUOTE(JSON_EXTRACT(name, '$.de')) LIKE '%Osłona pod silnik%'
   OR JSON_UNQUOTE(JSON_EXTRACT(name, '$.de')) LIKE '%osłona silnika%'
   OR JSON_UNQUOTE(JSON_EXTRACT(name, '$.de')) LIKE '%skrzyni biegów%';

-- podgląd konkretnych rekordów (max 20):
SELECT id, product_code,
       JSON_UNQUOTE(JSON_EXTRACT(name, '$.pl')) AS name_pl,
       JSON_UNQUOTE(JSON_EXTRACT(name, '$.de')) AS name_de
FROM products
WHERE JSON_UNQUOTE(JSON_EXTRACT(name, '$.de')) LIKE '%Osłona pod silnik%'
   OR JSON_UNQUOTE(JSON_EXTRACT(name, '$.de')) LIKE '%osłona silnika%'
LIMIT 20;

-- ile locków auto_matrix na slocie name/de (informacyjnie — lock ZOSTAJE po fixie):
SELECT COUNT(*) AS locki_auto_matrix_de
FROM translation_overrides
WHERE field = 'name' AND locale = 'de' AND source = 'auto_matrix';

-- ----------------------------------------------------------------------------
-- 2. Krzaki w locale spoza matrycy (po _fix_locales.php: 0)
--    Krzak = polska nazwa "Osłona pod silnik" siedząca w bg/en/et/hu/it/lt/lv/ro
-- ----------------------------------------------------------------------------
SELECT COUNT(*) AS krzaki_en
FROM products
WHERE JSON_UNQUOTE(JSON_EXTRACT(name, '$.en')) LIKE '%Osłona pod silnik%';

SELECT COUNT(*) AS krzaki_hu
FROM products
WHERE JSON_UNQUOTE(JSON_EXTRACT(name, '$.hu')) LIKE '%Osłona pod silnik%';

SELECT COUNT(*) AS krzaki_ro
FROM products
WHERE JSON_UNQUOTE(JSON_EXTRACT(name, '$.ro')) LIKE '%Osłona pod silnik%';

-- ----------------------------------------------------------------------------
-- 3. Forma PL (po _fix_pl_form.php: stara forma feedowa = 0)
--    Kanoniczna: "Stalowa osłona silnika X" (małe „o")
--    Stara feedowa: "Stalowa Osłona pod silnik X" (wielkie „O" + „pod")
-- ----------------------------------------------------------------------------
SELECT COUNT(*) AS pl_stara_forma
FROM products
WHERE CAST(JSON_UNQUOTE(JSON_EXTRACT(name, '$.pl')) AS BINARY) LIKE '%Osłona pod silnik%';

-- ----------------------------------------------------------------------------
-- 4. Atrybut „Materiał" (po _add_material_attribute.php)
-- ----------------------------------------------------------------------------
-- atrybut istnieje (1 wiersz):
SELECT id, slug, name FROM attributes WHERE slug = 'material';

-- wartości Stal/Aluminium (2 wiersze):
SELECT av.id, av.slug, av.name
FROM attribute_values av
JOIN attributes a ON a.id = av.attribute_id
WHERE a.slug = 'material';

-- ile produktów ma przypięty materiał (powinno = liczba wszystkich produktów):
SELECT av.slug AS material, COUNT(*) AS produktow
FROM attribute_value_product avp
JOIN attribute_values av ON av.id = avp.attribute_value_id
JOIN attributes a ON a.id = av.attribute_id
WHERE a.slug = 'material'
GROUP BY av.slug;

-- ============================================================================
-- 5. BAZA SKLEPU PrestaShop (bsplate.de / bsplate.sk) — PO delta syncu
--    Uwaga: prefix tabel może być inny niż ps_ (sprawdź w phpMyAdmin sklepu).
-- ============================================================================
-- ile produktów ma krótki opis (rośnie z 0 po delta syncu):
SELECT COUNT(*) AS z_krotkim_opisem
FROM ps_product_lang
WHERE description_short IS NOT NULL
  AND description_short <> '';

-- podgląd (5 sztuk) — powinno być HTML ze Stahlstärke/Schützt:
SELECT id_product, id_lang, LEFT(description_short, 120) AS short_preview
FROM ps_product_lang
WHERE description_short <> ''
LIMIT 5;
