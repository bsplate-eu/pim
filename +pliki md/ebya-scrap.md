# Scrapowanie eBay — sprzedawca Scut Protection (panzerplatten)

Notatka robocza: jak ściągać oferty konkurenta z eBay.de i jak to docelowo zintegrować przez API.

## Cel

Monitoring asortymentu i cen konkurenta **BSP** (bsplate.eu — osłony podwozia/silnika do aut).
Monitorowany sprzedawca na eBay:

- Nazwa wyświetlana: **Scut Protection**
- Sklep: **panzerplatten**
- Login (seller ID): **`scutprotectionsrl`**
- Rynek: **ebay.de**

### Cel końcowy (doprecyzowany przez usera)

1. Monitoring cen i nowości u sprzedawcy, **1x na 24h**.
2. Zbierane pola (minimalnie): **nazwa + kod producenta (Herstellernummer) + cena**.
3. Eksport zebranych danych po API (własny endpoint PHP nad bazą, JSON + token).

**Optymalizacja kosztu/czasu (ważne):** cena i nazwa są na liście wyników `/sch/?_ssn=`,
a Herstellernummer tylko na stronie pojedynczej oferty. Herstellernummer się nie zmienia, więc:
- codzienny check pobiera TYLKO listę (nazwa + cena + item_id) — szybko, tanio, bez wchodzenia w oferty;
- na stronę oferty wchodzimy TYLKO dla nowości (raz), żeby dociągnąć Herstellernummer.

To eliminuje drogie/wolne "visit each item page" z codziennego runu. Mocny argument za własnym
scraperem (PHP) zamiast płatnego actora Apify.

---

## Narzędzie: Apify

Actor: **`khadinakbar/ebay-all-in-one-scraper`** ("eBay Scraper - Listings, Sold Prices & Sellers (8 markets)")
Model rozliczeń: pay-per-event (płacisz za wynik, nie subskrypcja).

### Konfiguracja inputu (sprawdzona, działa)

| Pole | Wartość |
|------|---------|
| Search Query | *(pusty)* |
| Start URLs | `https://www.ebay.de/sch/i.html?_ssn=scutprotectionsrl&_ipg=240` |
| Listing Mode | Active listings only |
| eBay Marketplace | Germany (ebay.de) |
| Max Results | 5 na test, potem docelowo (np. 1000) |
| Visit each item page | **WŁĄCZONE** (daje Herstellernummer/EAN) |
| Proxy Configuration | **Residential** (grupa RESIDENTIAL) |

### Pułapki (rozwiązane w trakcie sesji)

1. **403 Forbidden** = brak residential proxy. Datacenter IP jest blokowane przez ebay.de.
   Rozwiązanie: w Proxy Configuration ustaw **Residential**.
2. **0 produktów (0 cards)** = zły format URL. Strona `/str/panzerplatten` to wizytówka sklepu, nie lista ofert.
   Rozwiązanie: użyj formatu wyszukiwania `/sch/i.html?_ssn=scutprotectionsrl` (login, nie nazwa sklepu).
3. `_ipg=240` = 240 ofert na stronę (maksimum). `_ssn=` = "pokaż wszystkie oferty tego sprzedawcy".

### Koszty

- Sam listing (bez "visit item page"): tani "result" event. 50 ofert = ~$0.17.
- Z "visit item page": droższy "detailed-result" event + wolniej (wchodzi na każdą ofertę).
- Residential proxy liczone osobno (~$8/GB).

---

## Pola w wynikach

### Podstawowe (zawsze)
title, price, currency, condition, listingType, isSold, soldDate, shippingCost,
sellerName, sellerFeedbackPercent, marketplace, itemUri, imageUri.

### Artikelmerkmale (tylko gdy "Visit each item page" = ON)
Sekcja cech produktu, np.:

```json
{
  "Hersteller": "Scut Protection",
  "Herstellernummer": "27.188",
  "Herstellergarantie": "2 Jahre",
  "EAN": "5941986210470"
}
```

**Herstellernummer** (numer producenta / MPN) i **EAN** to kluczowe pola do identyfikacji części.

---

## Integracja przez API (Apify REST API)

Token: Apify Console -> Settings -> API & Integrations -> "Personal API token".
Actor ID w API używa tyldy zamiast ukośnika: `khadinakbar~ebay-all-in-one-scraper`.

### Wariant 1 — run-sync (małe runy, 1 request odpala + zwraca dane)

```php
<?php
$token = 'APIFY_TOKEN';
$actor = 'khadinakbar~ebay-all-in-one-scraper';

$input = [
    'startUrls'   => [['url' => 'https://www.ebay.de/sch/i.html?_ssn=scutprotectionsrl&_ipg=240']],
    'maxResults'  => 50,
    'marketplace' => 'ebay.de',
    'visitItemPage' => true,
    'proxyConfiguration' => ['useApifyProxy' => true, 'apifyProxyGroups' => ['RESIDENTIAL']],
];

$ch = curl_init("https://api.apify.com/v2/acts/$actor/run-sync-get-dataset-items?token=$token");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($input),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true,
]);
$items = json_decode(curl_exec($ch), true);
```

> Uwaga: nazwy pól inputu (`maxResults`, `visitItemPage`, `marketplace`) zweryfikuj w konsoli Apify
> (Input -> przełącz na JSON) — skopiuj je 1:1.

### Wariant 2 — async (duże runy; run-sync ma limit ~5 min)

1. `POST /v2/acts/{actor}/runs?token=...` (body = input) -> zwraca `runId` + `defaultDatasetId`
2. Poll: `GET /v2/actor-runs/{runId}?token=...` aż status = `SUCCEEDED`
3. `GET /v2/datasets/{datasetId}/items?token=...&format=json`

Alternatywa do crona: wbudowany **Apify Scheduler** + webhook do endpointu na bsplate.eu.

---

## Docelowa architektura (zaprojektowana, NIE zaimplementowana)

Implementacja pójdzie w **osobnym projekcie** (nie w bspeu — to tylko strona firmowa).

### Baza (MySQL)

`ebay_products`: item_id (PK), seller, title, price, currency, condition,
hersteller, herstellernummer, ean, garantie, image_url, item_url,
first_seen, last_seen, is_active

`ebay_price_history`: id, item_id (FK), price, checked_at

### Przepływ

- **Krok 1 — full sync**: pełny zaciąg wszystkich ofert -> wypełnia `ebay_products`.
- **Krok 2 — check (cyklicznie)**: pobiera świeże dane i porównuje z bazą:
  - nowość (nieznany item_id) -> INSERT + flaga "new"
  - zmiana ceny -> UPDATE + wpis do `ebay_price_history`
  - zniknięcie (był, już go nie ma) -> `is_active = 0`

Schemat z polem `seller` => dorzucenie kolejnych sprzedawców później bez przeróbek.

### Otwarte decyzje (do ustalenia przed implementacją)

- Środowisko/scheduling: cron na prod / Windows Task Scheduler lokalnie / Apify Scheduler + webhook
- Częstotliwość: raz dziennie / co godzinę / co tydzień / ręcznie
- Powiadomienia: mail przez PHPMailer (SMTP już skonfigurowany) / tylko zapis do bazy / oba
