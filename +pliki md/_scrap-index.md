# Monitoring konkurencji (Scrap) — INDEKS

> Sesja robocza 2026-06-03. Dokumentacja całości: scraping eBay na Apify + architektura modułu w PIM/Argo + źródła danych + feasibility oficjalnych API.

## Pliki

1. **[ebay-scraper-apify.md](ebay-scraper-apify.md)** — własny scraper eBay na Apify (Playwright). Finalny działający kod, ustawienia, koszty, wpadki i lekcje.
2. **[argo-scrap-architektura.md](argo-scrap-architektura.md)** — docelowa architektura modułu **Scrap w PIM / Argo**. Mózg vs mięśnie, silnik diff, tabele, matching po ArtikelNr/EAN, mit VPN.
3. **[stahl-unterfahrschutz-source.md](stahl-unterfahrschutz-source.md)** — sklep konkurenta = **ten sam producent (Scut Protection)**, ten sam ArtikelNr co Herstellernummer z eBay. Łatwe, darmowe źródło katalogu.
4. **[marketplaces-api-feasibility.md](marketplaces-api-feasibility.md)** — oficjalne API: eBay (✅), Allegro (⚠️), Amazon (❌). Stan na 2026-06.
5. **[ebya-scrap.md](ebya-scrap.md)** — oryginalna notatka (płatny actor Apify khadinakbar). Historyczna, zachowana.

## Cel projektu

Monitoring asortymentu i cen konkurenta **BSP / Scut Protection** (osłony podwozia/silnika):
- **co nowego** się pojawiło,
- **co zostało wycofane**,
- **zmiana ceny**,
- pola minimalne: **nazwa + kod producenta (Herstellernummer / ArtikelNr) + cena**.

Klucz spinający wszystkie źródła i PIM: **ArtikelNr (= Herstellernummer) oraz EAN**.

## Stan na koniec sesji

| Element | Status |
|---|---|
| Własny actor eBay (Playwright) — listing | ✅ działa (240/strona, ~1500 = 7 stron) |
| Ekstrakcja Herstellernummer ze strony oferty | ✅ działa (`27.188`, `30.142`...) |
| Pełny zaciąg 1500 z HN przez scraping | ⚠️ drogi (~$10–12) i kruchy (timeout/migracja hosta) → fix: zapis na bieżąco |
| stahl-unterfahrschutz.eu jako źródło katalogu | 🟢 zidentyfikowane, łatwe, do zrobienia |
| Oficjalne API eBay | 🟢 decyzja podjęta — „będziemy się starać" |
| Moduł Scrap w PIM/Argo | 🟡 zaprojektowany, niezaimplementowany |

## Rekomendowana kolejność (następne kroki)

1. **stahl-unterfahrschutz.eu** — driver direct PHP (sitemap → produkty). Najłatwiejsze, darmowe, daje pełny katalog z ArtikelNr+EAN+cena.
2. **eBay API** — założyć konto dev + keyset, driver Browse API w PHP (ceny eBay). Zastępuje cały scraping eBay.
3. **Silnik diff + tabele** w PIM (logika nowe/wycofane/cena, matching po ArtikelNr).
4. **Allegro** — sprawdzić dostęp do offer-search; jak jest → driver API, jak nie → scrape.
5. **Amazon** — decyzja biznesowa (Keepa/Rainforest płatne albo odpuścić).

## Najważniejsza lekcja sesji

**Scraping pełnego katalogu eBay (wejście w 1500 ofert) to studnia bez dna** — ~$8–12 za run, kruche (timeout 1h, migracja hosta kasuje pamięć). Oficjalne API robi to za darmo w sekundy. **Herstellernummer i tak mamy za darmo na stahl-unterfahrschutz.eu** — eBay potrzebny tylko do cen eBay.
