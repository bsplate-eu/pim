# Selly — sesja 3 (handoff, kontynuacja `selly2.md`)

> Zakres: utrwalenie kategorii Selly (mapa per-produkt), kolejność w kategorii **z zestawami**,
> kolumna „Materiał" w liście produktów PIM + diagnoza atrybutu materiału.
> Poprzednie: `selly.md` (sesja 1), `selly2.md` (sesja 2).
> Prod PIM: `~/domains/pim.bsplate.eu/PIM`, PHP `/usr/local/php83/bin/php`.
> **NOWE:** PIM ma auto-deploy przez `git push` (cron 5 min) — patrz `docs/DEPLOY-PIM.md`. ZIP-y już niepotrzebne.

---

## 🟢 STAN NA KONIEC SESJI

| Element | Status | Uwaga |
|---|---|---|
| Feed bez „Kategoria ścieżka" (tylko ID) | ✅ NA PRODZIE | paczka `_deploy_selly_bez_sciezki` wgrana |
| Mapa kategorii per-produkt (override) | ✅ NA PRODZIE | `config/selly_category_overrides.php`, 1599 wpisów |
| Kolejność w kategorii — reguła + pliki | ✅ WYGENEROWANE | makieta + CSV, invariant 0 naruszeń |
| Import kolejności do Selly | 🔴 **NIE UDAŁO SIĘ** | zły klucz dopasowania w integratorze — patrz §3 |
| Kolumna „Materiał" w PIM (kod) | ✅ NA PRODZIE | commit `2b9fa52`, push OK, auto-deploy zaciągnął |
| Kolumna „Materiał” na prodzie | ✅ **DZIAŁA** | prod miał tylko 13 przypisań — dane uzupełnione (§5) |
| Auto-oznaczenie materiału wg kodu | ✅ ODPALONE NA PRODZIE | stal=1307, alu=328; rerun 0 zmian (§6) |
| Materiał odporny na `sync()` ze źródeł/importu | ✅ NA PRODZIE | commit `bcda40b` (§6b) |
| Klucze i18n `Material` / `Not set` | ✅ w repo, commit `fbc4cb6` | ⏸️ wymaga symlinka `public_html/lang` — §5 |

---

## 1. KATEGORIE — utrwalenie ręcznego ułożenia ✅ WDROŻONE

**Problem:** feed liczył kategorię z własnego drzewa PIM → każdy update mógł cofnąć ręczne ułożenie w sklepie.
Dodatkowo 7 nowych kategorii (Iveco eSuperJolly, Ford Tourneo Custom, Renault Twingo…) **nie istniało**
w `config/selly.php` (516 wpisów) — drzewo nigdy by ich nie odtworzyło.

**Rozwiązanie: nadrzędna mapa per-produkt.**
- `config/selly_category_overrides.php` — **1599 wpisów** `external_id => Kategoria ID`,
  wygenerowana z eksportu sklepu `1_export-product(5).csv` (= źródło prawdy, ręczne ułożenie usera).
- `config/selly.php` → klucz `category_overrides` (require pliku, brak pliku = pusta mapa).
- `SellyIntegrationProductsExport::resolveCategory()` — **override sprawdzany PIERWSZY**;
  produkt spoza mapy (nowość) leci starą logiką drzewa → fallback „1. XXX"/401.

**Weryfikacja (lokalnie, pełny feed):** 1482 z override zgodne, **0 MISMATCH**, 3 spoza mapy → fallback. ✅
**Wdrożone:** paczka `_deploy_selly_kategorie_override_2026-07-08.zip` + `optimize:clear` + czyszczenie cache feedu.

⚠️ **Regeneracja mapy** po zmianach w sklepie: nowy eksport Selly → przegenerować plik (skrypt w §7).

---

## 2. KOLEJNOŚĆ W KATEGORII — reguła + pliki ✅ WYGENEROWANE

### Reguła (ustalona z userem, przykład wzorcowy = Suzuki Grand Vitara)
```
1. materiał      : STAL przed ALU
2. single/zestaw : POJEDYNCZE przed ZESTAWAMI   ← klucz 2., krytyczny
3. rocznik       : malejąco (year-start)
4. typ osłony    : silnik → skrzynia → reduktor → chłodnica → zbiornik → dyferencjał → …
5. kod produktu  : rozstrzyga remisy
```

**⚠️ Dlaczego „single przed zestaw" MUSI być kluczem 2.:** zestawy mają inne zakresy lat niż single
(np. zestaw A4 B6 „2005-2008" vs single „2000-2005") → przy sortowaniu samym rocznikiem
**zestaw wskakiwał na 1. miejsce**. Kategoria = jedna generacja, więc zestawy zawsze na końcu bloku materiału.

**Invariant sprawdzony na WSZYSTKICH 524 kategoriach: `stal przed alu` = 0 naruszeń, `single przed zestaw` = 0 naruszeń.** ✅

### Zestawy — kluczowe odkrycie
- **169 zestawów w sklepie**, wszystkie z **PUSTYM „Kod importu"**, kody typu `30.005+00.005`, w 95 kategoriach.
- **W PIM ich NIE MA** — to twory rodzime sklepu, nigdy nie przeszły przez feed.
- Feed ma tylko **9** pakietów („…pakiet promocyjny") i te są **wykluczane** (`reject` po fladze `set`).
- ➡️ **Feed nie jest w stanie posortować zestawów.** Kolejność całości (single + zestawy) da się ustawić
  wyłącznie **importem po `Produkt ID`** — jedynym kluczu, który adresuje też zestawy.

### Wygenerowane pliki (root PIM)
| Plik | Zawartość | Rola |
|---|---|---|
| `_makieta_kolejnosc.html` | 524 kategorie, 1804 produkty, 169 zestawów | podgląd w przeglądarce, filtr po marce |
| `_selly_pelny_kolejnosc.csv` | **format Selly 1:1 (16 kolumn)**, przecinek | ⭐ główny — tylko „Kolejność" zmieniona |
| `_selly_kolejnosc_import.csv` | `Produkt ID;Kod importu;Kolejność`, `;`+BOM | minimalny |
| `_selly_kolejnosc_TEST.csv` | 38 wierszy, 3 kategorie (505/339/453) | test |

**Walidacja `_selly_pelny_kolejnosc.csv`:** 1804 wiersze × 16 kolumn, **0 produktów ze zmianą pól innych niż „Kolejność"**
(nazwy/ceny/kategorie/opisy/zdjęcia bajt w bajt jak w eksporcie), 1050 z nową pozycją.

---

## 3. 🔴 IMPORT KOLEJNOŚCI DO SELLY — NIE UDAŁO SIĘ (do dokończenia)

Trzy podejścia, wszystkie skończyły się `Pomijam...` (**0 zmian w sklepie — nic nie zepsute**):

| Próba | Klucz importu | Log | Przyczyna |
|---|---|---|---|
| 1 | Kod importu | `csv##opis##918 nie znaleziony` | Selly dokleja własny prefix `csv##` → inny namespace niż integrator feedu |
| 1b | Kod importu | `W polu c_1 nie podano wartości` | zestawy mają PUSTY Kod importu |
| 2 | ID produktu | `csv##9886 nie znaleziony` | **mapowanie pól pomieszane** (patrz niżej) |

### ⚠️ ROOT CAUSE próby 2 — auto-mapowanie zgadło źle
Na ekranie „Powiązywanie pól" było:
- `c_1` **Produkt ID** → zmapowane na **„Kod importu"** ❌ (stąd `csv##9886` = Produkt ID wciśnięte jako kod!)
- `c_2` **Kod producenta** → zmapowane na **„Kolejność w kategorii"** ❌

### ➡️ CO ZROBIĆ (następny krok)
Na ekranie **Powiązywanie pól**:
1. **„Zresetuj powiązania"** (czyści bałagan → wszystko „Pominięte")
2. `c_1` **Produkt ID** → **„ID produktu"**
3. `c_15` **Kolejność w kategorii** → **„Kolejność w kategorii"** (przewinąć na dół)
4. **reszta zostaje „Pominięte"**

Na ekranie wcześniejszym (Edycja schematu) — już ustawione poprawnie:
- **Klucz importu: „ID produktu"** ✅ · **Tryb: „tylko aktualizacja"** ✅ · **Separator `;`** ✅

Plik: `_selly_kolejnosc_import.csv` (separator `;`) **albo** `_selly_pelny_kolejnosc.csv` (przecinek — wtedy zmienić separator).

### ⚠️ Po udanym imporcie
**W integratorze feedu NIE mapować „Kolejność w kategorii"** — inaczej feed przenumeruje single 1..N
(bez zestawów) i rozwali układ. Kolejnością rządzi ten import.

### Dlaczego tylko `Produkt ID` (sprawdzone w danych)
- `Kod producenta` **NIE jest unikalny** — 388 grup duplikatów (358 wśród samych pojedynczych!)
- `EAN` — pusty u 505 pojedynczych i **168/169 zestawów**
- ➡️ **`Produkt ID` = jedyny unikalny klucz w sklepie**

---

## 4. KOLUMNA „MATERIAŁ" W LIŚCIE PRODUKTÓW PIM ✅ ZBUDOWANE

**Po co:** opis w szablonie Selly wybiera Stal/Aluminium **wyłącznie po atrybucie `material`** —
produkty dodane po przebiegu klasyfikatora nie miały go ustawionego i opis pisał „Stal" mimo kodu ALU
(zgłoszone przez usera: `14.103ALU` Mercedes B-Classe → „Materiał osłony: Stal").

### Co powstało (commit `2b9fa52`)
- `ProductController@index` — doładowuje wartość atrybutu `material` per produkt (pole `material` = slug albo `null`);
  filtr `material` z opcją **`none`** (produkty bez ustawionego materiału).
- `ProductController@updateMaterial` + `UpdateProductMaterialRequest` — inline zapis;
  **rusza WYŁĄCZNIE wartości atrybutu „Materiał"** (detach tylko po ID stal/aluminium), reszta atrybutów nietknięta.
- `routes/crafter.php` — `PUT admin/products/{product}/material` (`crafter.products.update-material`).
- `Product/Index.vue` — kolumna po „Name" (select: `—` / Stal / Aluminium) + filtr w FiltersDropdown.

### Weryfikacja (lokalnie, end-to-end)
```
GET /admin/products            → 200, materialOptions: Aluminium/Stal, klucz 'material' w wierszu: TAK
filtr aluminium                → 251, wszystkie faktycznie alu    |  filtr none → 0
PUT material=aluminium         → 302, zapisane, atrybutow 9 → 9  [inne atrybuty nietkniete]
PUT material=''                → 302, wyczyszczone (9 → 8)
PUT material='drewno'          → 302 odrzucone przez walidacje, bez zmiany
przywrocenie stanu             → OK
```

### ✅ PUSH ZROBIONY — kolumna jest na prodzie
`2b9fa52` **jest na `origin/main`** (potwierdzone `git branch -r --contains`), auto-deploy zaciągnął → kolumna
widoczna w PIM. Po drodze: push z serwera (`admin@pareto:~$`) i z `C:\Users\Pareto 1` = „not a git repository";
zadziałało dopiero `git -C "D:\laragon\www\PIM" push origin main` (repo jest na Windowsie, serwer sam robi pull).

**Do wypushowania zostaje `4981611`** (ten handoff + `deploy/material-auto-assign.php`):
```bash
git -C "D:\laragon\www\PIM" push origin main
```
Bez tego skryptu z §6 **nie ma na prodzie** i nie da się go tam odpalić.

---

## 5. ✅ ROZWIĄZANE (2026-07-27): kolumna na prodzie pokazywała WSZYSTKO PUSTE

Screen z proda: nagłówek **`crafter.Material`** (surowy klucz) + wszystkie wiersze „—".

### ⚠️ Hipoteza z tej sekcji BYŁA BŁĘDNA
Zakładała inny slug atrybutu na prodzie (`material-1` po kolizji `Sluggable`). Diagnoza wykazała:
```
ATTR id=9 slug='material' name(pl)='Materiał'
   VAL id=2606 slug='stal'        VAL id=2607 slug='aluminium'
```
**Slug był poprawny.** Kontroler znajdował atrybut bez problemu.

### Prawdziwe przyczyny — dwie, niezależne

**a) Wiersze „—": na prodzie było TYLKO 13 przypisań w pivocie** (11 stal / 2 alu),
wszystkie z datą `2026-07-10 10:16:14`. Danych po prostu nie było.
➡️ Wpis w pamięci projektu „prod: stal=1297/alu=314" **był błędny** — to były liczby z **lokalnej** bazy.
*Lekcja: liczby z pamięci projektu weryfikować zapytaniem, zanim zbuduje się na nich hipotezę.*

**b) Nagłówek `crafter.Material`: docroot NIE serwuje `PIM/public/lang/`.**
`public_html/lang/` to **fizyczna kopia z 14 maja**, nie symlink — w odróżnieniu od `build`, `media`,
`storage`, które symlinkami są. Każdy nowy klucz i18n dodany w repo **cicho ginie**.
Porównanie obu drzew: **0 różnic** poza nowymi kluczami → symlink bezpieczny.

### Wykonane
- klucze `crafter.Material` + `crafter.Not set` w 15 locale — commit `fbc4cb6`
- podmiana kopii na symlink (**ręcznie przez usera**, classifier blokuje mi zmiany struktury na prodzie):
```bash
ssh -i /d/laragon/www/SSH/bsp-auto admin@5.196.81.23 \
 'cd ~/domains/pim.bsplate.eu/public_html && mv lang lang.OLD-20260727 && ln -s /home/admin/domains/pim.bsplate.eu/PIM/public/lang lang'
```
Potem **Ctrl+Shift+R**. Stara kopia zostaje jako `lang.OLD-20260727` (rollback = `mv` z powrotem).

---

## 6. SKRYPT AUTO-OZNACZENIA MATERIAŁU ✅ ODPALONY NA PRODZIE (2026-07-27)

**`deploy/material-auto-assign.php`** — reguła zamówiona przez usera:
```
product_code zawiera "ALU"  →  Aluminium
pozostałe                   →  Stal
```

Co robi:
1. **DIAGNOZA** — atrybuty wyglądające na „Materiał" (slug `material*` lub nazwa z „materia"),
   ich wartości, slugi i liczba przypisanych produktów.
2. Używa istniejącego atrybutu (nie tworzy duplikatu); slug ≠ `material` → **prostuje** (kontroler szuka `material`).
3. Klasyfikuje wszystkie produkty **po kodzie** i synchronizuje pivot.
4. Raportuje **rozjazdy kod vs nazwa PL**.

**Bezpieczeństwo:** nie dotyka `products.name` ani tłumaczeń; w pivocie rusza wyłącznie wiersze
stal/aluminium; **idempotentny**; **domyślnie dry-run**.

```bash
/usr/local/php83/bin/php deploy/material-auto-assign.php            # dry-run
/usr/local/php83/bin/php deploy/material-auto-assign.php --apply    # zapis
```

### Wynik na prodzie
```
produktow: 1635  (aluminium: 328, stal: 1307)   bez kodu: 0
do dodania: 1623    do usuniecia (bledny material): 1    rozjazdy kod vs nazwa PL: 0
stan w bazie po zapisie:  stal=1307, aluminium=328
rerun (idempotencja):     do dodania 0, do usuniecia 0    ✅
```

---

## 6b. UTWARDZENIE — materiał odporny na sync (commit `bcda40b`) ✅

**Skąd te sieroce 13 przypisań:** `attributeValues()->sync()` robi **pełny sync** — kasuje wartości,
których przychodzący zestaw nie niesie. A materiału **nie niesie żadne źródło ani import**
(ustawiany ręcznie w PIM). Trzy miejsca kasowały go po cichu:

| Miejsce | Kiedy strzelało |
|---|---|
| `SumpguardSource.php:390,403` | `sources:sync` — **wyłączony na prodzie** po incydencie nazw |
| `ProductsImport.php:64` | **import Excela z panelu — aktywny** ← realne zagrożenie |
| `Product::setAttributeValues()` | zapis atrybutów z formularza |

**Fix:** `Product::syncAttributeValuesPreserving()` + stała `Product::PRESERVED_ATTRIBUTE_SLUGS = ['material']`.
Zasada **per atrybut chroniony**: zestaw niosący wartość tego atrybutu wygrywa (świadoma zmiana);
zestaw nienoszący nic → bieżąca wartość produktu zostaje nietknięta. Mapa chronionych wartości
cache'owana 1h (`protected_attribute_values`).

**Testy lokalne (produkt 1115, stan przywrócony):**
```
T1 zestaw bez materialu   → material zachowany TAK, pozostale atrybuty zgodne TAK
T2 zestaw z aluminium     → alu ustawione TAK, stal usunieta TAK
T3 produkt bez materialu  → nic nie doklejone TAK
restore                   → OK
```

➡️ **Dodanie kolejnego atrybutu chronionego** = dopisanie sluga do `PRESERVED_ATTRIBUTE_SLUGS`.

## 7. PROPOZYCJA (niezrobiona) — fix szablonu opisu

`docs/deploy/2026-07-14-prod-full/_selly_szablon_v3_material.html`, linia 1:
```php
@php($is_alu = ($attribute_material ?? '') == 'Aluminium')
```
Materiał w opisie zależy **tylko od atrybutu** — brak atrybutu = opis pisze „Stal" mimo kodu ALU.

**Fix (trwały, zabezpiecza też przyszłe produkty):**
```php
@php($is_alu = ($attribute_material ?? '') == 'Aluminium' || preg_match('/ALU/i', $product_code ?? ''))
```
`$product_code` jest w szablonie dostępny. Szablon wkleja się **w panelu Selly** (nie w repo).
**Decyzja usera: jeszcze nie podjęta.**

---

## 8. NOWOŚCI DO WYSTAWIENIA — zablokowane na braku feedu

User prosił o plik z nowościami dla oslonypareto.pl. **Nie da się policzyć z lokalnego PIM:**
- lokalne PIM = **1494** produkty, sklep = **1599** → lokalne jest **do tyłu**, nie zna najnowszych
- lokalny diff dał tylko 11 śmieci (8 pakietów promo bez ceny, Seat Leon, 2× `27.311b` Crafter/MAN — te do usunięcia)

**Potrzebny świeży feed z proda:**
```
https://pim.bsplate.eu/api/selly/8?key=610bcd2a278d3dfdeecd6128d3aa1d9b3af957a9e26b3f42282d9cde5fc3f266
```
(pierwsze wejście = 503 + generacja w tle → odświeżyć po ~10 s; plik cache: `storage/app/integrations/8.csv`, TTL 6h)

Potem: feed MINUS eksport sklepu po `opis##{external_id}` → `_selly_nowe.csv` → import z **„dodawanie nowych" ON**.
*(Przeglądarka Claude nie ma dostępu do prod feedu — user musi pobrać plik.)*

---

## 📋 OTWARTE PUNKTY (priorytetowo)

1. 🔴 **Dokończyć import kolejności** — poprawić „Powiązywanie pól" w Selly (§3): `c_1`→ID produktu, `c_15`→Kolejność, reszta Pominięte.
2. 🟡 **Symlink `public_html/lang` → `PIM/public/lang`** na prodzie (§5) — bez tego nagłówek kolumny
   pokazuje surowy klucz `crafter.Material`, a każdy przyszły klucz i18n ginie. Komenda w §5, wykonuje user.
3. 🟡 **Fix szablonu** `$is_alu` po kodzie (§7) — decyzja usera.
4. 🟡 **Nowości** — pobrać feed z proda, wygenerować plik (§8).
5. ⚪ Z `selly2.md`, nadal otwarte: ceny ręczne (`_cennik_manual.csv` + paczka importu), fix `getOverridedProduct()` na prod,
   duplikaty zestawów w Selly (Passat B5 ma 6 zestawów, część wygląda na duble), obce języki (composeForeign).

---

## 🔧 GOTCHAS (tej sesji)

- **Selly „Kod importu" jest per-integrator** — import dokleja własny prefix `csv##`, więc kody z feedu
  (`opis##…`) są **niewidoczne** dla innego schematu importu. Do aktualizacji istniejących produktów: **Produkt ID**.
- **Auto-mapowanie pól w Selly zgaduje źle** — sprawdzać ekran „Powiązywanie pól" ZANIM się puści import.
- **`Kod producenta` nie jest unikalny** (388 grup dubli) — nie nadaje się na klucz importu.
- **Zestawy istnieją tylko w sklepie**, nie w PIM — feed ich nie widzi i nie posortuje.
- **Sortowanie z zestawami:** `set` musi być kluczem **2.** (zaraz po materiale), inaczej zestaw z nowszym
  rocznikiem wskakuje nad single.
- **Polski cudzysłów `„…"` w stringu PHP** — zamykający `"` **kończy string** → parse error.
  W echo używać zwykłych znaków (parse error nie pokazuje szczegółu bez `-d display_errors=1`).
- **`php -l` bez `-d display_errors=1`** mówi tylko „Errors parsing", bez numeru linii.
- **`Sluggable` dokleja `-1`/`-2`** przy kolizji slugów — nie zakładać, że slug = to co ustawione w kodzie.
- **Auto-deploy PIM działa** (`git push` → cron 5 min → pull + lint + migrate + cache).
  `public/build` **jest w repo** (prod nie ma npm) — budować lokalnie i commitować razem ze zmianą.
  Push robić **na Windowsie**, nie na serwerze.
- **Build frontu:** `export PATH="/c/laragon/bin/nodejs/node-v18:$PATH"` przed `npm run build` (~25 s).
- **Tłumaczenia UI** siedzą w `public/lang/{locale}/crafter.json`, nie w `lang/`. Brak klucza = wyświetla `crafter.Klucz`.
- **ZIP-y na prod:** budować `ZipArchive` w PHP (forward-slashe). Python w tym środowisku niedostępny (alias Store).

---

## PLIKI TEJ SESJI

| Plik | Rola | Status |
|---|---|---|
| `config/selly_category_overrides.php` (1599) | mapa kategorii per-produkt | ✅ na prodzie (w repo, HEAD) |
| `_deploy_selly_kategorie_override_2026-07-08.zip` | paczka override kategorii | ✅ wdrożona |
| `_makieta_kolejnosc.html` | podgląd kolejności (524 kat.) | ✅ do wglądu |
| `_selly_pelny_kolejnosc.csv` | ⭐ format Selly 1:1, zmieniona tylko kolejność | 🔴 import nieudany |
| `_selly_kolejnosc_import.csv` | `Produkt ID;Kod importu;Kolejność` | 🔴 import nieudany |
| `_selly_kolejnosc_TEST.csv` | 38 wierszy, 3 kategorie | test |
| `deploy/material-auto-assign.php` | auto-materiał wg kodu + diagnoza | 🟡 nowy, niezacommitowany |
| `_deploy_kolumna_material_2026-07-08.zip` | zapas (auto-deploy czyni zbędnym) | — |
| commit `2b9fa52` | kolumna „Materiał" | ✅ na origin/main + prod |
| commit `4981611` | ten handoff + skrypt materiału | ⏸️ do wypushowania |

Źródło prawdy dla kategorii/kolejności: **`C:\Users\Pareto 1\Downloads\1_export-product(5).csv`**
(1804 wiersze: 1599 `opis##` + 205 spoza feedu; 0 w „1. XXX", 0 duplikatów, 519 kategorii).
