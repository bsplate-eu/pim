# Oficjalne API marketplace'ów — feasibility

> Sesja 2026-06-03. Stan zweryfikowany wyszukiwarką (czerwiec 2026). Dotyczy monitoringu **konkurenta** (cudze oferty), nie własnego konta.

| Platforma | API do monitoringu konkurenta? | Trudność |
|---|---|---|
| **eBay** | ✅ Browse API (q + `filter=sellers:`) | 🟢 łatwo, czysty PHP |
| **Allegro** | ⚠️ API jest, ale publiczny offer-search ograniczony/deprecated | 🟡 niepewne |
| **Amazon** | ❌ brak czystego API do monitoringu konkurenta | 🔴 trudno/drogo |

---

## eBay — ✅ ZIELONE (wybrana droga)

**3 fakty:**
1. **Finding API NIE ŻYJE** — `findItemsAdvanced` (łatwe „wylistuj wszystkie oferty sprzedawcy") **wyłączone 5 lutego 2025**. Odpada.
2. **Browse API wymaga słowa kluczowego** — `q` obowiązkowe, bez wildcardów; sam `filter=sellers:` nie wystarczy. **Obejście dla naszego sprzedawcy:** cały katalog to „Unterfahrschutz", więc:
   ```
   GET /buy/browse/v1/item_summary/search?q=Unterfahrschutz&filter=sellers:scutprotectionsrl&limit=200
   ```
   (+ paginacja `offset`). Łapie praktycznie wszystko. Pokrycie zweryfikować vs ~1500.
3. **Autoryzacja prosta i darmowa** — OAuth **client_credentials** → **Application token** (bez logowania usera), scope `https://api.ebay.com/oauth/api_scope`, ważny 2h. Kilka linii w PHP (Guzzle, Base64(client_id:secret) → POST).

**Herstellernummer/EAN:** przez `getItem` per oferta (`localizedAspects`) — oficjalnie, w limicie (~5000 calls/dzień).

**Jak zdobyć dostęp:**
1. Konto eBay Developer Program.
2. Application keyset (Production) → `client_id` + `client_secret`.
3. Application token (client_credentials).
4. Test: Browse search jw. (na start można Sandbox — keyset od ręki).
> Część „Buy API" bywa za zgodą/commercial agreement; podstawowy Browse search zwykle działa od razu na app tokenie.

➡️ **Decyzja: „będziemy się starać o developer API z eBay".** Zastępuje cały scraping eBay (za darmo, czysto, zgodnie z ToS).

---

## Allegro — ⚠️ ŻÓŁTE

**Plusy (jak eBay):**
- Oficjalne REST API, OAuth2 **client_credentials** dla danych publicznych.
- Rejestracja: `apps.developer.allegro.pl` → `client_id` + `client_secret`.
- Limit hojny: **9 000 req/min**; token 12h.

**Haczyk:** publiczny endpoint przeszukiwania **cudzych** ofert (`/offers/listing`) jest **deprecated / z ograniczonym dostępem**. Dla **swoich** ofert `/sale/offers` OK, ale **monitoring konkurenta** niepewny — może wymagać osobnej zgody Allegro albo być zamknięty. **Zweryfikować w portalu dev.** Jeśli zamknięte → scraping Allegro (anti-bot łagodniejszy niż Amazon).

---

## Amazon — 🔴 CZERWONE

**Brak czystego oficjalnego API do monitoringu konkurenta:**
- **SP-API** (Selling Partner) = **własne** konto sprzedawcy. Wymaga AWS, złożone. Nie wyciągnie katalogu konkurenta.
- **PA-API** (Product Advertising) = dla afiliantów. Prosty klucz, ale **bramkowane**: trzeba konto Associate z realną sprzedażą (bez sprzedaży tracisz dostęp), ostre limity.

**Realne opcje:**
1. **Płatne API third-party** (Keepa, Rainforest API, Canopy) — wyspecjalizowane w danych Amazona, subskrypcja. Najczystsze jeśli Amazon naprawdę potrzebny.
2. **Twardy scraping** (residential + przeglądarka) — kruche, pod górę z ToS.
3. **Odpuścić** — jeśli dla niszy (osłony podwozia) to marginalny kanał.

---

## Źródła (zweryfikowane 2026-06)

- eBay Browse API — Buy API Field Filters (`sellers:`): https://developer.ebay.com/api-docs/buy/static/ref-buy-browse-filters.html
- eBay Community — Filter by Seller ID (wymóg `q`): https://community.ebay.com/t5/Traditional-APIs-Search/Filter-by-Seller-ID-in-Browse-API/td-p/34671353
- eBay API Deprecation Status (Finding API decommissioned): https://developer.ebay.com/develop/get-started/api-deprecation-status
- eBay OAuth client credentials: https://developer.ebay.com/api-docs/static/oauth-client-credentials-grant.html
- Allegro REST API guideline: https://github.com/allegro/restapi-guideline
- Allegro `/offers/listing` (DEPRECATED): https://www.postman.com/allegro-rest-api/allegro-rest-api/request/8846xus/offers-listing
- Amazon SP-API vs Ad-API: https://www.sellerlabs.com/blog/amazon-sp-api-vs-ad-api-guide/
- Alternatywy dla Amazon PA-API (Canopy): https://www.canopyapi.co/blog/alternatives-amazon-product-advertising-api
