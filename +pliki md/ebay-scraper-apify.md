# eBay Scraper — własny actor na Apify (Playwright)

> Sesja 2026-06-03. Własny actor (nie płatny khadinakbar) na platformie Apify, JavaScript + Crawlee.
> Sprzedawca: **scutprotectionsrl** (Scut Protection), rynek **ebay.de**, ~**1500** aktywnych ofert.

---

## Kluczowe odkrycia techniczne (to, co kosztowało najwięcej czasu)

1. **eBay wymaga PRAWDZIWEJ przeglądarki — Cheerio nie wystarczy.**
   - `CheerioCrawler` (goły HTTP) dostaje **403** prawie zawsze. eBay ma **JS-challenge** (anti-bot ustawia cookie po wykonaniu JS).
   - Działa dopiero **`PlaywrightCrawler`** (realny Chromium + xvfb) — wykonuje JS, przechodzi challenge. Tak samo robi płatny actor.
   - Nawet Playwright dostaje hałdę `WARN 403`, ale **przebija się** dzięki rotacji sesji/IP.

2. **Residential proxy OBOWIĄZKOWE.** Datacenter IP = 403. `groups: ['RESIDENTIAL'], countryCode: 'DE'`.

3. **eBay zmienił layout listingu — nowe selektory.**
   - Stare `li.s-item` = **martwe (0 trafień)**.
   - Nowe: **`.s-card`** (≈ 242 karty/stronę = 240 ofert + 2 reklamy-placeholdery).
   - Placeholdery do odfiltrowania: `title === "Shop on eBay"`, fałszywe `itemId === "123456"`, link na `ebay.com`.
   - Tytuł ma doklejony śmieć: „**Wird in neuem Fenster oder Tab geöffnet**" → trzeba strip.
   - Cena jako tekst „EUR 178,00" → parsować do liczby (format DE: `.` = tysiące, `,` = dziesiętne).

4. **Herstellernummer jest TYLKO na stronie pojedynczej oferty** (sekcja „Artikelmerkmale"), nie na liście. I się **nie zmienia**.
   - Ekstrakcja przez label-value: `<dt>Herstellernummer</dt><dd>27.188</dd>` lub eBay `ux-labels-values`.
   - Działa: `27.188`, `30.142`, `17.115`, `08.214`... + EAN `5941986...`.
   - Część ofert nie ma HN (sprzedawca nie wypełnił) → `null`, ale EAN zwykle jest.

5. **Pakiety w szablonie Apify:** NIE `crawlee` (zbiorczy), tylko **`@crawlee/playwright`** (i `@crawlee/cheerio` w szablonie Cheerio). Import z `crawlee` → `ERR_MODULE_NOT_FOUND`.

6. **`parseWithCheerio()`** w kontekście PlaywrightCrawler → ten sam parser `$(...)` co w Cheerio, na realnie wyrenderowanej stronie.

---

## WPADKI i lekcje (ważne — kosztowały $)

| Problem | Przyczyna | Fix |
|---|---|---|
| Run timed out po 1h, **Output 0**, **$8.81** | `maxDetails: 0` = wejście w WSZYSTKIE 1500 ofert (~5h przy concurrency 2) + **kod zapisywał dopiero na końcu** → timeout = wszystko przepadło | zapis **na bieżąco** (`pushData` per oferta) + timeout 14400 + max cost cap |
| Druga połowa runu `HN="undefined"` | **„Run was migrated to a new host"** — Apify przeniósł run, pamięć (`Map` w RAM) się wyczyściła | nie trzymać stanu w RAM — `userData` w requeście + push od razu |
| 403 masowo | normalne dla eBay | `maxRequestRetries: 12–15`, Crawlee rotuje sesje |
| Build error `Cannot find package 'crawlee'` | zły import | `from '@crawlee/playwright'` |
| 512 MB → crash | Chromium potrzebuje RAM | Memory ≥ 2048, dla concurrency 8 → 8192 |

**ZABEZPIECZENIE:** zawsze ustawiaj **Max cost per run** (np. $1–15). Domyślnie „Unlimited" — stąd $8.81 za nic.

**Koszt realny pełnego zaciągu 1500 z Herstellernummer: ~$10–12** (residential proxy na 1500 stron ofert). Tyle kosztuje scraping eBay — i dlatego docelowo **oficjalne API** (za darmo) albo **Herstellernummer ze stahl-unterfahrschutz.eu**.

---

## FINALNY KOD — `src/main.js` (zapis na bieżąco, odporny na timeout/migrację)

```js
import { Actor } from 'apify';
import { PlaywrightCrawler } from '@crawlee/playwright';

await Actor.init();

const {
    sellerId = 'scutprotectionsrl',
    domain   = 'www.ebay.de',
    maxPages = 7,
    concurrency = 8,
} = (await Actor.getInput()) ?? {};

const proxyConfiguration = await Actor.createProxyConfiguration({ groups: ['RESIDENTIAL'], countryCode: 'DE' });

function cleanTitle(t) {
    return (t || '').replace(/Wird in neuem Fenster oder Tab geöffnet\s*$/i, '').replace(/Opens in a new window or tab\s*$/i, '').trim();
}
function parsePrice(raw) {
    if (!raw) return null;
    const first = raw.split(/\s+bis\s+|\s+to\s+/i)[0];
    const d = first.replace(/[^\d.,]/g, '').replace(/\./g, '').replace(',', '.');
    const f = parseFloat(d);
    return Number.isFinite(f) ? f : null;
}
function aspect($, label) {
    let val = null;
    const re = new RegExp('^\\s*' + label + '\\s*$', 'i');
    $('dt').each((_, dt) => { if (re.test($(dt).text().trim())) { const dd = $(dt).nextAll('dd').first(); if (dd.length) { val = dd.text().trim(); return false; } } });
    if (val) return val;
    $('[class*="labels"]').each((_, el) => { const $el = $(el); if (re.test($el.text().trim())) { const v = $el.closest('[class*="labels-values"]').find('[class*="values"]').first(); if (v.length) { val = v.text().trim(); return false; } } });
    return val;
}

const seen = new Set();

const crawler = new PlaywrightCrawler({
    proxyConfiguration,
    maxConcurrency: Number(concurrency) || 8,
    maxRequestRetries: 12,
    requestHandlerTimeoutSecs: 90,
    navigationTimeoutSecs: 60,
    preNavigationHooks: [ async ({ page }) => {
        await page.route('**/*', (route) => {
            const t = route.request().resourceType();
            if (['image', 'media', 'font', 'stylesheet'].includes(t)) return route.abort();   // oszczędza proxy
            return route.continue();
        });
    } ],
    async requestHandler({ page, parseWithCheerio, request, addRequests, log }) {
        if (request.label === 'LIST') {
            await page.waitForSelector('.s-card', { timeout: 30000 }).catch(() => {});
            const $ = await parseWithCheerio();
            const toDetail = [];
            $('.s-card').each((_, el) => {
                const $c = $(el);
                const href = $c.find('a[href*="/itm/"]').attr('href') || '';
                const itemId = (href.match(/\/itm\/(\d+)/) || [])[1] || null;
                const title = cleanTitle($c.find('[class*="title"]').first().text());
                const priceRaw = $c.find('[class*="price"]').first().text().trim();
                if (!itemId || itemId === '123456' || /^shop on ebay$/i.test(title) || !title || seen.has(itemId)) return;
                seen.add(itemId);
                toDetail.push({ url: `https://www.ebay.de/itm/${itemId}`, label: 'DETAIL', userData: { itemId, title, price: parsePrice(priceRaw), priceRaw } });
            });
            await addRequests(toDetail);
            log.info(`LIST ${request.url} → ${toDetail.length} ofert`);
            return;
        }
        if (request.label === 'DETAIL') {
            await page.waitForSelector('text=Artikelmerkmale', { timeout: 15000 }).catch(() => {});
            const $ = await parseWithCheerio();
            const { itemId, title, price, priceRaw } = request.userData;
            const herstellernummer = aspect($, 'Herstellernummer') || aspect($, 'Hersteller-Teilenummer') || null;
            const ean = aspect($, 'EAN') || null;
            await Actor.pushData({ itemId, title, price, priceRaw, herstellernummer, ean, url: `https://www.ebay.de/itm/${itemId}` });  // ZAPIS OD RAZU
            log.info(`SAVED ${itemId}: HN="${herstellernummer}"`);
            return;
        }
    },
});

const listUrls = [];
for (let p = 1; p <= maxPages; p++) listUrls.push({ url: `https://${domain}/sch/i.html?_ssn=${sellerId}&_ipg=240&_pgn=${p}`, label: 'LIST' });
await crawler.run(listUrls);

console.log(`KONIEC. Przetworzono ${seen.size} ofert.`);
await Actor.exit();
```

---

## Input (`.actor/input_schema.json`) — pola

`sellerId` (string), `domain` (string), `maxPages` (int, 1 strona ≈ 240 ofert, 7 = wszystkie ~1500), `concurrency` (int).

## Uruchomienie

**Input → JSON:**
```json
{ "maxPages": 7, "concurrency": 8 }
```

**Run options (przy Starcie):**

| Pole | Wartość | Po co |
|---|---|---|
| Timeout | **14400** (4h) | żeby zdążył przejść 1500 ofert |
| Memory | **8192** MB | więcej CPU = szybciej (CPU był wąskim gardłem) |
| Max cost per run | **15** | sufit bezpieczeństwa (było Unlimited → $8.81) |

**Tylko lista (tanio, ~5–10 min):** `maxPages: 7`, a w kodzie nie kolejkować DETAIL (lub osobny wariant listing-only). Daje 1500 × nazwa+cena+itemId za grosze.

---

## Dane wyjściowe (Dataset)

```json
{
  "itemId": "236769650186",
  "title": "Stahl Unterfahrschutz für Motor VW Transporter T4 Caravelle - (1990-2003)",
  "price": 178,
  "priceRaw": "EUR 178,00",
  "herstellernummer": "27.188",
  "ean": "5941986210470",
  "url": "https://www.ebay.de/itm/236769650186"
}
```

## Wniosek

Działa, ale **scraping pełnego katalogu eBay jest drogi i kruchy**. Docelowo:
- **Herstellernummer** → bierzemy ze **stahl-unterfahrschutz.eu** (ten sam ArtikelNr, za darmo).
- **Ceny eBay** → **oficjalne Browse API** (za darmo) albo tania lista.
- Ten actor zostaje jako fallback / dowód koncepcji.
