# Źródło: stahl-unterfahrschutz.eu

> Sesja 2026-06-03. Sklep konkurenta — **kluczowe odkrycie sesji**.

---

## Co to jest i dlaczego ważne

`https://www.stahl-unterfahrschutz.eu/` — sklep ze stalowymi osłonami podwozia/silnika.

**To TEN SAM producent i te same produkty co sprzedawca z eBay:**
- Hersteller na stronie produktu: **`Scut Protection S.R.L`** (= `scutprotectionsrl` z eBay).
- **ArtikelNr** w formacie `30.142` = **dokładnie format Herstellernummer z eBay** (`27.188`, `17.115`...).
- **EAN** ten sam prefix `59419862...` co na eBay.

➡️ **Oba kanały spinają się 1:1 po ArtikelNr / EAN.** To uniwersalny klucz: eBay ↔ sklep ↔ PIM.

---

## Czemu to ŁATWE źródło (dużo łatwiejsze niż eBay)

| Cecha | Wartość |
|---|---|
| Platforma | custom („Programed By lokopi WEB"), mały sklep |
| Anti-bot | **brak agresywnego** → wystarczy goły HTTP (Guzzle/Cheerio), **bez residential, bez Playwright** |
| Lista URLi | **sitemap**: `https://www.stahl-unterfahrschutz.eu/unterfahrschutz-sitemap` → **~1500 URLi**, bez zgadywania paginacji |
| Herstellernummer | **ArtikelNr + EAN od razu na stronie produktu** (nie trzeba osobnych wejść jak na eBay) |
| Koszt | grosze, kilka minut |

Organizacja: po markach aut (`/audi`, `/bmw`...) i modelach. URL produktu np.:
`/unterfahrschutz-fur-motor-der-marke-audi-a1-2010-2015`

---

## Dane na stronie produktu (przykład Audi A1)

```
Title:            Unterfahrschutz für Motor der Marke Audi A1 (2010-2017)
Price:            149 € (z 161 €, rabat -7%); 123,14 € netto
ArtikelNr:        30.142          ← = Herstellernummer
EAN-Code:         5941986200044
Material:         Stahl, 2 mm
Komponenty:       motor, getriebe, kühler, vordere stossfänger
Gewicht:          11 kg
Ölwannenöffnung:  Ja
Hersteller:       Scut Protection S.R.L
Garantie:         24 Monate
Lieferzeit:       4 bis 7 Werktage
```

Pola do zebrania: **title, price (+ original/rabat), ArtikelNr, EAN, material, grubość, komponenty, waga, marka/model/rok**.

---

## Plan parsera (driver)

1. Pobierz **sitemap** `/unterfahrschutz-sitemap`.
2. Wyfiltruj **URL produktów** (priorytet 1.0; wzorzec `unterfahrschutz-fur-motor-...`), pomiń strony kategorii (marka/model).
3. Dla każdego URL: HTTP GET (Guzzle) → parsuj (DomCrawler / Cheerio) label-value („ArtikelNr.", „EAN-Code"...).
4. Znormalizuj do wspólnego formatu i wpnij w silnik diff.

**Tech:** w PIM = PHP **Guzzle + symfony/dom-crawler**. Bez proxy (ew. jeden tani proxy dla higieny IP). Uprzejmie: małe opóźnienia, niska współbieżność, respektuj robots.txt.

---

## Wartość biznesowa

- **Pełny katalog** Scut Protection z ArtikelNr/EAN/specyfikacją/ceną sklepową — idealne do PIM.
- **Porównanie cen**: ten sam produkt — cena sklepu vs cena eBay (po ArtikelNr/EAN).
- Tańsze i pewniejsze niż scraping eBay; **Herstellernummer mamy stąd za darmo**, więc eBay potrzebny tylko do cen eBay.

➡️ **To jest rekomendowane PIERWSZE źródło do zaimplementowania.**

---

## ✅ ZREALIZOWANE (2026-06-25)

Wdrożone w PIM jako kanał **„Niemcy"** (tab „Sklep 1" przemianowany na „Niemcy") w Argo Scope → Scrapy → Rumuni.

**Pliki:**
- `app/Services/Stahl/StahlScrapClient.php` — `productUrls()` (sitemap → URL-e priority 1.0) + `parseProduct()`. Selektory (zwalidowane na żywo): `h1[itemprop=name]` (tytuł), `span[itemprop=price]` (cena brutto/aktualna), `<p>ArtikelNr.: …</p>` (herstellernummer, z sufiksem ALU), `span[itemprop=gtin13]` (EAN), `#addToCartButton[data-product-id]` (**external_id** — stabilny/unikalny; ArtikelNr NIE jest unikalny). Cena sprzed rabatu `span.discount` + netto/VAT/marka → kolumna `raw`.
- `app/Services/Stahl/StahlScrapService.php` — `fullSync()`: upsert do `scrap_products(source='stahl')` + silnik diff (nowe/wycofane/ceny ↑↓) → `scrap_changes` + staty → `scrap_sources`. Per-stronę try/catch (jedna wadliwa strona nie ubija crona).
- `app/Jobs/RunStahlSync.php` (przycisk „Pobierz z Niemcy", kolejka `default`) · `app/Console/Commands/SyncStahlScope.php` (`scope:sync-stahl --limit= --delay=`) · cron dzienny 04:00 (`App\Console\Kernel`).
- `app/Models/Scrap/ScrapSource.php` + migracja `scrap_sources` (staty per kanał, karmią kafelki monitoringu jak eBay).

**Filtr produktów:** crawl URL-i o `priority 1.0` (kategorie marka/model = 0.6/0.8 → odpadają); parser zwraca `null` dla stron bez `data-product-id` (~6 stron statycznych: kontakt/versand/… → pomijane). Wynik: ~1597 produktów.

**Match do PIM:** po ArtikelNr↔`product_code` / EAN (`ProductMatcher`, przycisk „Przypisz do SKU") — wspólny z eBay (ten sam producent Scut Protection). Klucz spina eBay ↔ stahl ↔ PIM 1:1.
