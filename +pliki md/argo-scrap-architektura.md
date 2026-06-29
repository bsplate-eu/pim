# Moduł Scrap — architektura (PIM / Argo)

> Sesja 2026-06-03. Docelowy moduł monitoringu konkurencji. Zaprojektowany, **niezaimplementowany**.
> Lokalizacja: **Argo scope** (moduł Scrap), nie bezpośrednio w PIM.

---

## Cel

Monitoring konkurentów (eBay, sklepy, docelowo Allegro/Amazon):
- **co nowe / co wycofane / zmiana ceny**,
- pola: **nazwa + Herstellernummer/ArtikelNr + cena**,
- **porównanie cen** tego samego produktu między kanałami (eBay vs sklep),
- spięcie z katalogiem **PIM**.

Klucz spinający wszystko: **ArtikelNr (= Herstellernummer) + EAN**.

---

## Zasada: MÓZG vs MIĘŚNIE

| Warstwa | Gdzie | Reużywalna? |
|---|---|---|
| **Mózg** — orchestracja, baza, diff, matching po ArtikelNr, porównanie cen, harmonogram, powiadomienia, UI | **moduł Scrap w PIM/Argo (Laravel)** | ✅ jedna, dla wszystkich źródeł |
| **Mięśnie** — pobranie danych z konkretnego źródła | **driver per źródło** | ❌ każde źródło swój |

Każde nowe źródło = **nowy driver** wpięty w ten sam silnik. Silnik diff piszemy raz.

---

## Architektura wg trudności źródła

| Źródło | Sposób | Trudność | Koszt |
|---|---|---|---|
| **stahl-unterfahrschutz.eu** | direct scrape PHP (Guzzle + DomCrawler), sitemap → produkty | 🟢 łatwe | grosze |
| **eBay** | **oficjalne Browse API** (PHP, JSON) | 🟢 łatwe (po dostępie) | $0 |
| **Allegro** | API jeśli dostęp do offer-search, inaczej scrape | 🟡 niepewne | ? |
| **Amazon** | brak czystego API → Keepa/Rainforest (płatne) albo odpuścić | 🔴 trudne/drogie | $$ |

> **eBay-detail-scraping wypada** — Herstellernummer bierzemy ze stahl (za darmo) albo z eBay API. Scraping detali eBay był drogi i kruchy (patrz `ebay-scraper-apify.md`).

---

## ⚠️ MIT: VPN / OpenVPN NIE rozwiązuje eBay

Częsty błąd myślowy „dam OpenVPN i nie zbanują IP":
- OpenVPN = **JEDNO datacenter IP** — a eBay blokuje właśnie datacenter IP (to są te 403).
- Jedno IP = **zbanowane w godziny** → cały pipeline pada.
- eBay wymaga **rotacji setek residential IP + przeglądarki** — VPN nie daje ani jednego, ani drugiego.

**Wnioski:**
- VPN dla eBay = **niewystarczający** (użyj API albo płatnego residential proxy z rotacją).
- VPN/proxy dla **łatwych stron** (stahl) = opcjonalna higiena (jeden tani proxy, żeby ban nie ubił IP serwera PIM) — ale nie warunek działania.

---

## Silnik diff (logika „Etap C")

Wymaga **PAMIĘCI między uruchomieniami** (sam scrape widzi tylko „teraz"). W PIM = tabela DB; w prototypie Apify było Key-Value Store.

Każdy run:
1. Wczytaj poprzedni stan (snapshot z bazy).
2. Pobierz aktualne dane ze źródła (driver).
3. Porównaj:
   - **nowość** = itemId/ArtikelNr w aktualnych, brak w poprzednich → INSERT + flaga „new".
   - **zmiana ceny** = jest w obu, cena różna → UPDATE + wpis do historii cen.
   - **wycofanie** = był w poprzednich, brak w aktualnych → `is_active = 0`.
4. Herstellernummer/ArtikelNr: pobieraj **tylko dla nowości** (nie zmienia się), resztę przepisz ze stanu.
5. Zapisz nowy stan + raport zmian.

---

## Tabele (szkic)

```
scrap_sources       (id, name, driver[direct|apify|ebay_api|allegro_api], config json, schedule, active)
scrap_products      (id, source_id, external_id[ArtikelNr|itemId], title, price, currency,
                     ean, herstellernummer, url, first_seen, last_seen, is_active, raw json)
scrap_price_history (id, product_id, price, checked_at)
scrap_changes       (id, source_id, type[new|removed|price], product_id, old_price, new_price, detected_at)
```

`scrap_products.external_id` + `herstellernummer/ean` = klucze matchingu między źródłami i do PIM.

## Kod (szkic)

```php
interface Scraper {
    public function fetch(): array;   // znormalizowane produkty: [itemId/artikelNr, title, price, ean, herstellernummer, url]
}

class StahlUnterfahrschutzScraper implements Scraper { /* sitemap → Guzzle → DomCrawler */ }
class EbayApiScraper             implements Scraper { /* Browse API: q + filter=sellers: + getItem */ }
class AllegroApiScraper          implements Scraper { /* jeśli dostęp do offer-search */ }

class DiffService     { /* nowe/wycofane/zmiana ceny + historia */ }
class ProductMatcher  { /* spina źródła + PIM po ArtikelNr/EAN */ }
```

Harmonogram: Laravel scheduler (tygodniowo per źródło). Powiadomienia: PHPMailer (SMTP już skonfigurowany) — mail przy zmianach.

---

## Następne kroki

1. Driver **stahl** (najłatwiejsze, pełny katalog z ArtikelNr).
2. Driver **eBay API** (po keysecie).
3. **DiffService** + tabele.
4. **ProductMatcher** (spięcie po ArtikelNr/EAN + PIM).
5. Allegro / Amazon — wg `marketplaces-api-feasibility.md`.
