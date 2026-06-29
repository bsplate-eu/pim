# EBAY SCRAP — narzędzie monitoringu konkurencji w PIM

> Zbudowane 2026-06. Monitoring konkurenta **Scut Protection** („Rumuni") na eBay.de
> (seller `scutprotectionsrl`, ~1565 ofert). Źródło danych: **oficjalne eBay Browse API**, NIE scraping.
> Powiązany research: [ebay-scraper-apify.md](ebay-scraper-apify.md), [marketplaces-api-feasibility.md](marketplaces-api-feasibility.md), [stahl-unterfahrschutz-source.md](stahl-unterfahrschutz-source.md).

---

## Dlaczego API, nie scraping

Scraping eBay = **403 wszędzie** (goły HTTP, lokalny Playwright/Chrome, Apify Playwright+residential — i listing, i detale). Spalone ~**$61** na Apify, zanim przeszliśmy na API. **eBay Browse API** (OAuth client_credentials) zwraca wszystkie ~1565 ofert sprzedawcy w sekundy, za **$0**, zgodnie z ToS. **Nie wracać do scrapingu eBay.**

Keyset: eBay Developer Program (konto BSP), Production App ID `BSPBlack-PIM-PRD-...`. Wymóg „Marketplace Account Deletion" obejść przez **exemption** „I do not persist eBay data". `client_secret` **ZROTOWAĆ** (był w czacie) — Rotate na developer.ebay.com → podmienić w UI integracji.

---

## Architektura — dwa miejsca w UI

| Miejsce | Co | 
|---|---|
| **Argo Connect → Integracje → Ebay** | KONFIGURACJA integracji: App ID, Cert ID (szyfrowany), sprzedawca, rynek, słowo-klucz, „Testuj połączenie" |
| **Argo Scope → Scrapy → Rumuni → [Ebay / Sklep 1 / Sklep 2 / Raport]** | DANE: oferty, mapowanie, porównanie cen, zatwierdzanie do cennika |

Podział celowy: *integracja* (połączenie) osobno od *danych* (monitoring).

---

## Pliki

**Backend:**
- `app/Services/Ebay/EbayBrowseClient.php` — klient Browse API: `token()` (client_credentials, cache 2h), `searchSeller()` (q + `filter=sellers:{id}`, paginacja), `itemAspects()` (getItem → HN/EAN, retry 4× przy 429/5xx), `testConnection()`.
- `app/Services/Ebay/EbayScrapService.php` — `sync()` (szybki: nazwa+cena), `fullSync()` (pełny: + aspekty + diff + cennik + statystyki), `fillMissingAspects()` (dobiera braki HN/EAN), `updatePricelist()` (ceny→„Ebay - Cennik").
- `app/Services/Scrap/ProductMatcher.php` — auto-mapowanie oferta↔nasz produkt (HN↔product_code, ean↔ean).
- `app/Models/Scrap/EbaySettings.php` (tab. `scrap_ebay_settings`), `ScrapProduct.php` (`scrap_products`), `ScrapChange.php` (`scrap_changes`).
- `app/Http/Controllers/Admin/Connect/IntegrationEbayController.php` — ustawienia integracji (index/update/testConnection/sync).
- `app/Http/Controllers/Admin/Scope/ScopeRumuniController.php` — dane: index, sync, assignProduct, searchProducts, createPricelist, setCompare, setTarget, approve, updateAll.
- `app/Console/Commands/`: `SyncEbayScope` (`scope:sync-ebay`), `FillEbayAspects` (`scope:fill-ebay-aspects`), `MatchScrapProducts` (`scope:match-products`).
- `app/Jobs/RunEbayFullSync.php` — pełny pomiar w tle (queue).
- routes: `crafter.connect.integrations.ebay.*`, `crafter.scope.rumuni.*`.

**Frontend (Vue + Inertia):**
- `resources/js/crafter/Pages/Connect/Integrations/Ebay/Index.vue` — ustawienia integracji.
- `resources/js/crafter/Pages/Scope/Scrapy/Rumuni/Index.vue` — główny widok (taby, tabela, mapowanie, cennik, zatwierdzanie).
- `resources/js/crafter/Components/Sidebar.vue` — pozycje „Integracje · Ebay" (Connect) + grupa „Argo Scope → Scrapy → Rumuni".

**Migracje:** `scrap_products`, `scrap_ebay_settings`, `scrap_changes`, + product_id/match_type, compare_pricelist/vat, target_pricelist.

---

## Przepływ użytkownika

**Etap 0 — integracja:** Connect → Integracje → Ebay: wpisz App ID + Cert ID, „Testuj połączenie".

**Etap 1 — pomiar + mapowanie:** Argo Scope → Scrapy → Rumuni → Ebay:
- „Pełny pomiar" → 1565 ofert + HN/EAN + diff (kafelki: Nowości / Wycofane / Ceny ↑↓).
- Auto-match oferta↔nasz produkt (po kodzie). Reszta: kolumna „Nasz produkt" → `+ przypisz` (wyszukiwarka, polskie nazwy).
- Filtry: Mapowanie (Przypisane/Nieprzypisane) · SKU (Z/Bez) · Cena (Z ceną/Bez ceny). Sortowanie kolumn, wyszukiwarka.

**Etap 2 — porównanie cen:**
- „+ Utwórz cennik" → nowy cennik.
- „Cennik do porównania" + VAT% → kolumna **Cena cennik** (brutto), kolorowanie: 🔴 my drożsi / 🟢 my tańsi.
- Kolumna **Różnica** = cena eBay − cena cennik (+ eBay drożej / − taniej).

**Etap 3 — zatwierdzanie do cennika:**
- „Cennik docelowy" — gdzie trafiają ceny.
- Kolumna **Zatwierdź** (checkbox). Default zaznaczone **tylko zielone** (my tańsi → bezpieczna podwyżka). Czerwone (my drożsi) i cena=0 → odznaczone (ręczna decyzja).
- „Zatwierdź zaznaczone" → wybrane oferty → cennik docelowy.
- „Aktualizuj cennik" → WSZYSTKIE zmapowane (cena>0) hurtem → cennik docelowy (przy duplikatach product_id bierze najniższą cenę).

Kolumny tabeli: Nazwa · Herstellernummer · Nasz produkt · EAN · Cena · Cena cennik · Różnica · **Indywidualna** · Zatwierdź · Link.

**Cena indywidualna** (kolumna na prawo od „Różnica"): ręczne pole na cenę. Pusta/0 = brak wpływu (do cennika idzie cena eBay jak dotąd). Wpisana (>0) = **ona** (brutto, jak eBay) idzie do cennika zamiast ceny eBay — i przy „Zatwierdź zaznaczone", i przy „Aktualizuj cennik" (cena efektywna = `individual_price ?? price`, do cennika jako netto). Wpisanie ceny auto-zaznacza ofertę (jeśli zmapowana). Zapis natychmiastowy (`scope.rumuni.individual`), kolumna `scrap_products.individual_price`.

---

## Waluty — wszystko porównywane w EUR

Waluty sklepów: Niemcy/Francja/Czechy/Hiszpania = EUR (CZ wg meta), Węgry = HUF, Rumunia = RON. Cenniki nasze bywają w EUR/PLN/CZK. **Wszystko sprowadzone do EUR po kursie EBC z dnia poprzedniego** (`App\Services\Scrap\CurrencyConverter::toEur`, frankfurter.app, cache 12h; brak kursu → pozycja pomijana):

- **Kolumna CENA** — cena oferty przeliczona na EUR (oryginał, np. „53200 HUF", w tooltipie). Prop `price_eur` liczony w `channelProducts`.
- **Kolumna CENA CENNIK** — nasz cennik porównawczy znormalizowany do EUR (gdy cennik w PLN/CZK → `pricelistEurRate()` × kurs).
- **RÓŻNICA, kolorowanie, „Aktualizuj cennik", „Zatwierdź"** — po EUR (`effectivePriceEur` → `targetNetPrice(...,$fx)`; Vue `offerPrice` = `price_eur`). Cena indywidualna dla Węgier wpisywana w walucie źródła i przeliczana.
- **Bez** osobnej kolumny „Cena EUR" i paska kursu (cena EUR jest wprost w kolumnie CENA). VAT per kanał (Węgry ÁFA 27%).

## Komendy CLI

```
php artisan scope:sync-ebay           # pełny pomiar (oferty + HN/EAN + diff + cennik)
php artisan scope:fill-ebay-aspects   # dobierz brakujące HN/EAN (rate-limited)
php artisan scope:match-products ebay # auto-mapowanie po kodzie/EAN
```
PHP: `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe`.

---

## Cennik — jak działa aktualizacja

> **PER KANAŁ (od 2026-06-25):** cennik porównawczy, VAT i cennik docelowy są **osobne dla każdego tabu** (Ebay / Niemcy / Sklep 2) — trzymane w `scrap_sources` (`compare_pricelist_id`, `compare_vat`, `target_pricelist_id`), nie globalnie. Kontroler: `channelConfig(source)`; prop `configs` per source; Vue `loadChannelConfig()` na `watch(activeTab)`. Zmiana w jednym tabie NIE rusza pozostałych.


> **VAT — kluczowe:** eBay podaje ceny **brutto**, cennik trzyma **netto**. Pole „VAT %" (`compare_vat`) działa w obie strony: do porównania netto cennika → brutto (`×(1+VAT)`); przy zapisie brutto eBay/indywidualnej → netto (`÷(1+VAT)`). Dzięki temu po reload „Cena cennik" wraca ~do ceny eBay (round-trip).

- **Porównawczy** (lewy) = tylko odczyt; ceny → kolumna „Cena cennik" (netto ×VAT = brutto). Zmiana w PIM widoczna po reload.
- **Docelowy** = ceny eBay/indywidualne (`PricelistProduct::upsert` po product_id — nadpisuje/dodaje), **zapisywane jako NETTO** (`toNet`: brutto ÷ (1+VAT)). Przez „Zatwierdź zaznaczone" lub „Aktualizuj cennik".
- **„Ebay - Cennik"** (slug `blacksteelplate-de-kopia`) — auto-aktualizowany przy `fullSync`/`fillMissingAspects` (match HN↔product_code), ceny **netto** (brutto eBay ÷ (1+VAT)).

---

## Gotchas / lekcje

- **eBay rate-limit:** masowy `fullSync` (~1565 getItem) → część 429 → puste HN. `itemAspects` ma retry (4× backoff). fullSync potrafi zawisnąć — `scope:fill-ebay-aspects` dobiera braki wolniej (0.3s/szt). Wynik: HN 1565/1565.
- **Build frontu:** node NIE w PATH → `export PATH="/c/laragon/bin/nodejs/node-v18:$PATH"` przed `npm run build` (inaczej `'node' is not recognized`).
- **Vite manifest race:** `npm run build` kasuje `public/build` i odtwarza na końcu — odświeżenie strony w tym oknie = „Vite manifest not found". NIE buildować, gdy user jest na stronie; po buildzie Ctrl+Shift+R.
- **Product.name = pole tłumaczone** (translatable, obiekt `{pl,de,…}`). W UI wyciągać `pl` (helper `plName` po stronie Vue — obsługuje obiekt i string-JSON).
- **Match po kodzie:** `herstellernummer` (eBay) ↔ `product_code` (PIM) = ten sam numer Scut Protection. Drugi klucz: EAN.

---

## TODO

- **Cron 1×/dobę** (`scope:sync-ebay`) w Laravel schedulerze — automatyczny pomiar (teraz ręcznie/przyciskiem).
- **Rotacja Cert ID** (był w czacie).
- ✅ **Sklep 1 = „Niemcy"** (stahl-unterfahrschutz.eu) — ZREALIZOWANE 2026-06-25: driver `App\Services\Stahl\*`, komenda `scope:sync-stahl`, cron dzienny 04:00, przycisk „Pobierz z Niemcy". Szczegóły: [stahl-unterfahrschutz-source.md](stahl-unterfahrschutz-source.md). Zostaje **Sklep 2**.
- Tab „Raport" — porównanie cen między kanałami (eBay ↔ sklep).
