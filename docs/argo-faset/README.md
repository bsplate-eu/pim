# Argo Faset — wytyczne do modułu fasetowej wyszukiwarki (OpenCart)

**Status:** ✅ **WDROŻONE 2026-07-09** (moduł napisany, indeks zapełniony, connector 1.2.0, zweryfikowane E2E). **Data:** 2026-07-09.
**Zastępuje:** zewnętrzny moduł „Virsal" (finder konfiguratora). **W tym projekcie NIE używamy nazwy „Virsal"** — nowy moduł, tabele i kod noszą nazwę **Argo Faset** (prefiks tabel `oc_argo_facet*`).

---

## ✅ Stan wdrożenia (2026-07-09)

Zrealizowane na sklepie **bspnew** (repo `github.com/bsplate-eu/bsp-new`):
- Indeks `oc_argo_facet` — **13 035 wierszy / 1491 produktów** (backfill `docs/argo-faset/_argo_facet_backfill.php`).
- Moduł OC: **catalog** (`extension/module/argo_facet` — strona findera Marka→Model→Baujahr + panel faset z licznikami + AJAX) + **admin** (konfiguracja faset bocznych + wpis w menu „Argo Modules → Argo Faset").
- **Home finder** (bsp.js, „Wählen Sie eine Abdeckung…") przepięty z kategorii na Argo Faset (po `value_code`); link **„Fahrzeug-Finder"** w nagłówku (desktop/sticky/mobile).
- **Connector 1.2.0**: `writeProductFacets()` + `CREATE oc_argo_facet` w `ensurePimTables()`; zweryfikowany E2E (delete → delta → odtworzenie indeksu).
- Fix 8 uszkodzonych etykiet DE (`??`) u źródła (PIM): chlodnice/radiator→Kühler, przedni-zderzak→Vordere Stoßstange, rame-pomocnicza→Hilfsrahmen, …

**Wszystkie kryteria akceptacji (sekcja 11) spełnione.** Artefakty w repo bsp-new: `docs/argo-faset/` (ta specyfikacja + `install.xml` OCMOD + `_argo_facet_backfill.php`).

> Uwaga (dane): faseta **Material** pokazuje na razie tylko „Stahl" — 251 produktów Aluminium ma `status=0`/`price=0` (brak ceny w cenniku); pojawią się automatycznie po nadaniu cen.

---

## 1. Cel

Fasetowa wyszukiwarka + finder **Marka → Model → Rok (Baujahr)** na sklepie OpenCart, zasilana atrybutami produktów z PIM. Klient zawęża ofertę po:
- **Marka** (`make`), **Model** (`model`) — fasety zależne (model filtrowany po wybranej marce),
- **Rok produkcji** — dopasowanie „pasuje do rocznika X" po zakresie `year-start … year-stop`,
- opcjonalnie: `engine`, `gearbox`, `oil`, `protection`.

## 2. Zasada naczelna: fasety mapujemy po KODZIE, nie po etykiecie

Etykiety atrybutów są wielojęzyczne („Marka"/„Marke"/„Make"). **Nigdy nie filtruj po tekście etykiety** — używaj stabilnych slugów z PIM:

| Atrybut PIM (slug) | Rola w fasetach |
|---|---|
| `make` | faseta Marka |
| `model` | faseta Model (zależna od `make`) |
| `year-start` | dolna granica rocznika (numeryczna) |
| `year-stop` | górna granica rocznika (numeryczna) |
| `engine`, `gearbox`, `oil`, `protection` | fasety dodatkowe (opcjonalne) |

## 3. Co PIM już dostarcza (kontrakt danych — GOTOWE)

PIM (connector `pim-connector-opencart.php`, pipeline'y `CatalogCreate/Delta`) przy każdym imporcie produktu wysyła w payloadzie klucz `attributes[]`:

```json
"attributes": [
  { "group_code": "make",       "group_name": "Marka",      "group_name_i18n": {"de":"Marke"},
    "value_code": "bmw",        "value_name": "BMW",        "value_name_i18n": {"de":"BMW"} },
  { "group_code": "model",      "group_name": "Model",      "value_code": "e46", "value_name": "E46", ... },
  { "group_code": "year-start", "group_name": "Year Start", "value_code": "2010","value_name": "2010", ... },
  { "group_code": "year-stop",  "group_name": "Year Stop",  "value_code": "2016","value_name": "2016", ... }
]
```

`group_code`/`value_code` (stabilne slugi) dodane 2026-07-09 — **to jest baza dla facetów**. `*_i18n` = etykiety do wyświetlenia per język.

Connector **już** zapisuje z tego:
- **`oc_product_attribute`** — wszystkie atrybuty jako tekst (zakładka „Specification"). Do WYŚWIETLANIA, nie do faset.
- **`oc_bsp_product_year`** (`product_id, year_from, year_to`) — zakres rocznika, zasila finder Baujahr. Ekstrahowane z `year-start`/`year-stop` **po `group_code`** (od 2026-07-09; wcześniej po etykiecie).

## 4. Docelowy indeks faset `oc_argo_facet` (DO WDROŻENIA razem z modułem)

`oc_product_attribute` (tekst, po etykiecie) i `oc_bsp_product_year` (tylko rok) nie wystarczą na wydajne, wielojęzyczne, zależne fasety. Moduł ma czytać **dedykowany, znormalizowany indeks** keyed po kodach:

```sql
CREATE TABLE IF NOT EXISTS `oc_argo_facet` (
  `product_id`  INT NOT NULL,
  `attr_code`   VARCHAR(64)  NOT NULL,   -- 'make','model','year-start','engine'...
  `value_code`  VARCHAR(191) NOT NULL,   -- 'bmw','e46','2010'... (slug wartosci)
  `value_label` VARCHAR(255) NOT NULL,   -- etykieta do UI (per jezyk)
  `value_num`   INT NULL,                -- wartosc numeryczna (rok) lub NULL
  `language_id` INT NOT NULL,
  PRIMARY KEY (`product_id`,`attr_code`,`value_code`,`language_id`),
  KEY `idx_attr_val` (`attr_code`,`value_code`,`language_id`),
  KEY `idx_attr_num` (`attr_code`,`value_num`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 4a. Connector: `writeProductFacets()` (gotowy do wpięcia w connector)

Dodać do `ensurePimTables()` `CREATE TABLE` jak wyżej, i wołać `writeProductFacets($productId, $item)` obok `writeProductAttributes()`/`writeProductYears()` (linie ~788 i ~874). Zasada replace, jak reszta:

```php
/**
 * Znormalizowany indeks faset (Argo Faset) z atrybutow payloadu.
 * Wymaga group_code + value_code (stabilne slugi z PIM). Brak 'attributes' = nie ruszamy.
 */
private function writeProductFacets(int $productId, array $item): void
{
    if (!array_key_exists('attributes', $item)) {
        return;
    }
    $attributes = is_array($item['attributes']) ? $item['attributes'] : [];

    $this->db->prepare('DELETE FROM ' . DB_PREFIX . 'argo_facet WHERE product_id=?')->execute([$productId]);
    if (empty($attributes)) {
        return;
    }

    $defaultCode = $this->codeOf($this->defaultLangId);
    $stmt = $this->db->prepare(
        'INSERT INTO ' . DB_PREFIX . 'argo_facet (product_id, attr_code, value_code, value_label, value_num, language_id)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE value_label=VALUES(value_label), value_num=VALUES(value_num)'
    );
    foreach ($attributes as $a) {
        if (!is_array($a)) continue;
        $attrCode = trim((string) ($a['group_code'] ?? ''));
        $valCode  = trim((string) ($a['value_code'] ?? ''));
        if ($attrCode === '' || $valCode === '') continue; // faseta wymaga kodow
        $raw      = trim((string) ($a['value_name'] ?? ''));
        $num      = preg_match('/^\d{1,6}$/', $raw) ? (int) $raw : null;
        $valueI18n = $this->normalizeI18n($a['value_name_i18n'] ?? null, $a['value_name'] ?? null);
        foreach ($this->langMap as $code => $langId) {
            $label = (string) ($valueI18n[$code] ?? $valueI18n[$defaultCode] ?? $raw);
            if (trim($label) === '') $label = $raw;
            $stmt->execute([$productId, $attrCode, $valCode, $label, $num, (int) $langId]);
        }
    }
}
```

> Uwaga: wdrażać **razem z modułem** (dopiero wtedy jest konsument tabeli) i przetestować na PUSTYM `demo.bsplate.de`. Zmiana payloadu (`group_code`/`value_code`) zmienia hash → pierwsza delta po wdrożeniu przepcha wszystkie produkty i zapełni indeks.

## 5. Moduł OpenCart — architektura

Standardowy layout modułu OC 3.x (`admin/` + `catalog/`):
- `catalog/controller/module/argo_facet.php` — render bloku faset + endpoint AJAX (`index.php?route=module/argo_facet.ajax`).
- `catalog/model/module/argo_facet.php` — zapytania do `oc_argo_facet` (+ `oc_bsp_product_year` dla roku).
- `catalog/view/theme/*/template/module/argo_facet.twig` — widok findera/faset.
- `admin/controller|model|view/module/argo_facet.php` — konfiguracja (które `attr_code` są fasetami, kolejność, etykiety grup, tryb roku).
- `install.xml` (OCMOD) — wstrzyknięcie bloku faset na liście kategorii/wynikach; **CREATE TABLE `oc_argo_facet`** przy instalacji (idempotentnie, gdyby connector jeszcze nie utworzył).

## 6. Finder Marka → Model → Rok

1. **Marka** — `SELECT DISTINCT value_code, value_label FROM oc_argo_facet WHERE attr_code='make' AND language_id=? ORDER BY value_label`.
2. **Model (zależny)** — po wyborze marki: modele tylko produktów, które mają wybraną markę:
   ```sql
   SELECT DISTINCT f2.value_code, f2.value_label
   FROM oc_argo_facet f1
   JOIN oc_argo_facet f2 ON f2.product_id=f1.product_id AND f2.attr_code='model' AND f2.language_id=f1.language_id
   WHERE f1.attr_code='make' AND f1.value_code=:make AND f1.language_id=:lang
   ORDER BY f2.value_label;
   ```
3. **Rok** — dropdown lat; dopasowanie „pasuje do rocznika": `oc_bsp_product_year.year_from <= :rok AND year_to >= :rok` (INNER JOIN po product_id). Alternatywnie z `oc_argo_facet` (`attr_code IN ('year-start','year-stop')` + `value_num`), ale `oc_bsp_product_year` jest gotowe i wydajniejsze na zakres.
4. **Wynik** — przecięcie (`INTERSECT` przez JOIN/`GROUP BY … HAVING COUNT(DISTINCT attr_code)=N`) → lista `product_id` → standardowy listing OC.

Fasety dodatkowe (`engine`, `gearbox`…) — ta sama mechanika co Model (JOIN po `product_id`).

## 7. Wydajność
- Indeksy jak w DDL (`idx_attr_val`, `idx_attr_num`). Dla dużych katalogów rozważyć tabelę zliczeń (facet counts) odświeżaną cronem.
- Zapytania faset zawężaj zawsze do `language_id` bieżącego sklepu.
- Przecięcie wielu faset: jedno zapytanie z `GROUP BY product_id HAVING COUNT(...)`, nie N podzapytań.

## 8. SEO
- Czyste URL-e faset z `value_code` (slug), np. `/osłony/bmw/e46/2012`, nie po id.
- `canonical` na wersję bez zbędnych parametrów; kombinacje wielofasetowe → `noindex,follow` (unikanie thin/duplicate).
- Finder = formularz GET → czytelne, linkowalne adresy.

## 9. UI/UX
- Blok findera (Marka→Model→Rok) nad listingiem + panel faset z boku (licznik przy każdej wartości).
- Wartości bez wyników = wyszarzone/ukryte (zależnie od licznika).
- Zależność: zmiana Marki resetuje Model; AJAX bez przeładowania.

## 10. Migracja z legacy „Virsal"
- Connector **nadal chroni** produkty-konfiguratory z zewnętrznej tabeli `oc_virsal_attr_meta` (odczyt w `isConfiguratorProduct()`), żeby sync ich nie nadpisał. To celowe do czasu pełnego wygaszenia starego modułu.
- Argo Faset i „Virsal" mogą działać równolegle w okresie przejściowym (różne tabele). Po migracji: wyłączyć stary moduł, a odczyt `oc_virsal_*` w connectorze można usunąć (osobna decyzja).
- **W nowym kodzie/nazwach: wyłącznie „Argo Faset".**

## 11. Kryteria akceptacji
- [x] Marka/Model/Rok zwracają poprawne przecięcia na `demo.bsplate.de`.
- [x] Model zależny od Marki (brak „sierot").
- [x] „Rok 2013" pokazuje produkty z `year_from ≤ 2013 ≤ year_to`.
- [x] Fasety działają w języku sklepu (etykiety `value_label`, klucze `value_code`).
- [x] Zero zależności od nazwy „Virsal" w kodzie modułu.
- [x] Indeks `oc_argo_facet` zapełnia się po `create → delta` (po wdrożeniu `writeProductFacets`).

---
Powiązane: connector `storage/app/pim-connector-opencart.php`, pipeline'y `CatalogCreate/DeltaPipeline::buildAttributes` (źródło `group_code`/`value_code`), `_WDROZENIE_OPENCART_2026-07-08.md`.
