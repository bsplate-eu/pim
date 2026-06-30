# Scope eBay — monitoring konkurenta per rynek — 2026‑06‑30

Rozbicie monitoringu eBay (Argo Scope → Rumuni → Scut Protection) z jednego rynku (DE) na **6 osobnych rynków**. Każdy rynek = osobny kanał (tab) z własnymi ofertami, cenami i walutą. Wcześniej zaciągaliśmy tylko ~1/6 ofert konkurenta.

---

## 1. Co ustaliliśmy (sonda Browse API na koncie `scutprotectionsrl`)

Konkurent prowadzi **5 realnie odrębnych katalogów** — sam pisze aukcje w języku rynku, różne `itemId`, różne ceny:

| Rynek | ~Oferty | Waluta | Tytuły |
|---|--:|---|---|
| eBay.de | ~1566 | EUR | niemieckie (*Unterfahrschutz*) |
| eBay.es | ~1122 | EUR | hiszpańskie (*cubre carter*) |
| eBay.it | ~991 | EUR | włoskie (*paramotore*) |
| eBay.fr | ~1128 | EUR | francuskie (*protection sous moteur*) |
| eBay.co.uk | ~1131 | **GBP** | angielskie (*sump guard / skid plate*) |

Plus **eBay.ch** (~329, **CHF**) — to ten sam katalog DE pokazany na szwajcarskiej domenie w CHF (podzbiór z powodu cła), nie osobny katalog. Zostawiony jako kanał `ebay_ch` (widok CHF).

**NIE są osobnymi rynkami** (to katalog DE zlokalizowany przez eBay „international visibility"): eBay **.at / .nl / .be** (1:1 niemiecki), **.pl** (DE w PLN + tytuł auto‑tłumaczony), **.ie** (DE po angielsku MT). Odpytane lokalnym językiem dają **0**.

**Nieobecny:** eBay US, AU, CA, HK, SG, MY, PH, IN, TW.

> **GOTCHA (klucz):** słowa‑zalążki w `searchSeller` **muszą być w języku rynku**. Niemieckie „Unterfahrschutz" na FR/IT/ES/UK zwraca **0**, bo tytuły są lokalne. To dlatego stary, jednorynkowy scraper widział tylko katalog DE.

---

## 2. Implementacja

**Backend** (`app/Services/Ebay/`, `app/Jobs/`, `app/Console/`):
- `EbayScrapService::MARKETS` — mapa rynek→`marketplace`/`currency`/`keywords` (lokalne słowa). Helpery `forMarket()`, `isMarket()`, `marketKeys()`. `fullSync()` pisze `source=ebay_xx` + walutę rynku, statystyki do `scrap_sources` (jak sklepy). Klucz **`ebay` = rynek DE** (zachowany dla zgodności istniejących danych/mapowań — **bez migracji**).
- `EbayBrowseClient::searchSeller($seller, array $keywords)` — keywordy z parametru zamiast zaszytych niemieckich.
- `RunEbayFullSync(?string $source)` — pomiar jednego rynku lub wszystkich.
- `scope:sync-ebay {source?}` — komenda per rynek / wszystkie.

**Kontroler** (`Admin\Scope\ScopeRumuniController`):
- `SOURCES` = 6 rynków eBay + 6 sklepów; `TAB_LABELS` (krótkie etykiety tabów).
- `index()` renderuje **data‑driven**: mapy `channels` / `meta` / `configs` / `unmapped` + `order` + `labels` (zamiast osobnych propsów per źródło).
- `channelEurRate()` ogarnia **GBP/CHF/HUF/RON → EUR** przez `CurrencyConverter` (ECB/frankfurter.app). `channelMeta()` ujednolica meta eBay (integracja z `EbaySettings`, statystyki z `scrap_sources`).

**Front** (`Pages/Scope/Scrapy/Rumuni/Index.vue`):
- Przerobiony z 7 zahardkodowanych propsów na **mapę kanałów** (`props.channels[tab]`) — skaluje się na 13 tabów.
- **Puste taby (count 0) chowają się**; przycisk **„Pokaż wszystkie (N)"** je odsłania. Raport i aktywny tab zawsze widoczne.

**Cron** (`app/Console/Kernel.php`): DE codziennie (rynek główny), pozostałe 5 rozłożone `dayOfYear % 3` (~2/dzień) — żeby nie przekroczyć dziennego limitu Browse API (każdy rynek to ~1,5 tys. ofert × `getItem`).

**Cennik:** auto‑push do „Ebay - Cennik" zachowany **tylko dla DE** (zgodność wsteczna). Pozostałe rynki używają cennika docelowego per‑kanał + „Aktualizuj cennik" (jak sklepy).

**Bonus:** naprawiony build‑blocker w `Connect/Integrations/Ebay/Offers.vue` — polski cudzysłów `"` zamykał przedwcześnie string JS (`Unterminated string constant`), przez co padał cały `vite build`.

---

## 3. Weryfikacja (na żywym API, lokalne creds)

- FR: 1128 ofert, tytuły francuskie, EUR. UK: 1131, angielskie, **GBP**. CH: 329, niemieckie, **CHF**.
- Kontroler `index()` zwraca 12 kanałów; **GBP→EUR** i **CHF→EUR** przeliczają się poprawnie (np. 281,88 CHF → 305,67 EUR; kurs 1,0844).
- `vite build` OK, `php -l` czysty.

---

## 4. Wdrożenie (PROD)

- **Bez migracji** (DE = `ebay`, dane/mapowania/ceny indywidualne/wykluczenia nietknięte).
- Wgrać zmienione pliki PHP/Vue **oraz cały `public/build`** (gitignored — budowany lokalnie/przy deployu), potem `php artisan optimize:clear`.
- Pierwsze dane na nowych rynkach: „Pełny pomiar" na każdym tabie (z HN/EAN + auto‑mapowanie po SKU) albo cron nadrobi w ~3 dni.
