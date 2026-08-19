# eBay — wystawianie i zarządzanie ofertą · wzorzec z OMS ARGO (Allegro)

> **Status:** rozpoznanie zakończone (2026-08-18). Kod jeszcze nie powstał.
> **Źródło wzorca:** `github.com/ArgoAgencyIT/argo-pim`, gałąź `feature/allegro-wystawianie`.
> **Decyzja:** nasz moduł eBay budujemy na architekturze modułu Allegro z OMS ARGO — ten sam
> zestaw ekranów, ten sam podział odpowiedzialności, ta sama kolejność etapów.
> **Powiązane:** [`+pliki md/EBAY-zarzadzanie.md`](../../+pliki%20md/EBAY-zarzadzanie.md) (co mamy dziś).

---

## 1. Najważniejsze ustalenie: to nie jest „integracja Allegro", to szkielet marketplace'u

W OMS ARGO ten sam wzorzec jest powielony dla **trzech** marketplace'ów (Allegro, Arena, Empik),
a w repo czekają gałęzie kolejnych (Amazon SP-API, Ceneo, eMAG, Kaufland, PHH). Każdy dostaje
identyczny komplet **6 ekranów**:

```
Connect → Marketplace → {Rynek} →
    Kategorie i parametry     ← „nauczone" kategorie + mapowanie parametrów na atrybuty PIM
    Szablony aukcji           ← układ opisu z tagami ([nazwa], [cena], [sku]…)
    Schematy                  ← PRZEPIS wystawiania per kategoria
    Wystawianie               ← lista produktów PIM → zaznacz → „Wystaw wg schematu"
    Zarządzanie aukcjami      ← lustro ofert + operacje masowe
    Cenniki dostaw            ← słowniki z konta
```

⚠️ **Nie ma wspólnej klasy bazowej ani interfejsu.** Sprawdziłem `app/Contracts/` — jest tylko
`ShopConnectorInterface` (sklepy) i `ConnectorPipelineInterface`. Każdy marketplace to osobny
namespace (`app/Services/Allegro`, `app/Services/Arena`, `app/Services/Empik`) z **identycznie
nazwanymi plikami**. Wzorzec jest **konwencją, nie abstrakcją** — kopiuje się kształt, nie kod.

Konsekwencja dla nas: eBay = siódmy marketplace w tym samym kształcie. Nie wymyślamy struktury.

---

## 2. Podział odpowiedzialności (co robi który plik)

| Warstwa | Allegro (wzorzec) | Rola |
|---|---|---|
| **Konto** | `AllegroAccount` + `AllegroOAuthService` | OAuth, tokeny szyfrowane, multi-account |
| **Klient HTTP** | `AllegroClient` | Bearer + auto-refresh + backoff 429 |
| **Lustro ofert** | `AllegroOfferSyncService` → `connect_allegro_offers` | pobranie stanu z rynku |
| **Kategoria** | `AllegroCategory` (`parameters`, `parameter_map`) | cache parametrów kategorii + mapowanie na atrybuty PIM |
| **Szablon opisu** | `AllegroListingTemplate` + `…Listing\AllegroListingTemplateRenderer` | sekcje + tagi → opis oferty |
| **Schemat** | `AllegroScheme` | przepis: kategoria + konto + cennik + szablon + tryb publikacji |
| **Automat parametrów** | `…Listing\AllegroOfferDraftBuilder` | produkt PIM → tytuł + parametry oferty (deterministycznie, **bez AI**) |
| **Publikacja** | `…Listing\AllegroOfferPublishService` | złożenie payloadu + wysyłka + zapis wyniku |
| **Ekran wystawiania** | `AllegroListingController` | lista produktów, podgląd, `publish()` |
| **Ekran zarządzania** | `AllegroOfferController` | lustro + 4 operacje masowe |

Kluczowy szczegół: **`Listing/` to osobny podkatalog serwisów.** Sync ofert i wystawianie to dwie
różne odpowiedzialności i w OMS są fizycznie rozdzielone. U nas dziś wszystko siedzi w jednym
`EbayOfferService` — to trzeba rozdzielić.

---

## 3. Model danych do odwzorowania

### `connect_allegro_schemes` → u nas `ebay_schemes`

```
name                      „Osłony silnika DE"
allegro_account_id        konto (u nas: rynek/marketplace + konto)
category_id               kategoria rynku (u nas eBay categoryId, np. 14769)
category_name             cache nazwy
title_template            „{name} {sku}"
listing_template_id       FK → szablon opisu
pricelist_id              cennik PIM = ŹRÓDŁO CENY
price_multiplier          mnożnik
tax_percent               VAT
default_stock             stan startowy
default_shipping_rate_id  cennik dostawy (u nas: fulfillment policy)
publication_mode          draft | active   ← BEZPIECZNIK
safety_information        GPSR (u nas: odpowiednik eBay — do ustalenia)
enabled
```

### `connect_allegro_offers` → mamy już `ebay_offers`, brakuje kolumn

Nasza tabela pokrywa: `item_id`/`sku`/`marketplace`/`title`/`price`/`quantity`/`listing_status`/
`product_id`/`raw`. **Brakuje:**

| Kolumna | Po co |
|---|---|
| `listed_at` | data wystawienia — sortowanie „ostatnio wystawione" |
| `validation_status` | `OK` / `WARNINGS` / `FAILED` — czy szkic da się aktywować |
| `validation_errors` (json) | komunikaty rynku, pokazywane w UI po najechaniu |
| `validated_at` | kiedy rynek zweryfikował |
| `error_message` | ostatni błąd operacji |
| `payload_hash` | dirty-check przy resyncu |
| `scheme_id` | wg którego schematu wystawione |

---

## 4. Przepływ wystawiania (`AllegroOfferPublishService::publish`)

Kolejność jest przemyślana i przenosi się 1:1:

```
1. Konto połączone?                         → wyjątek
2. Cennik dostawy jest?                     → wyjątek
3. Schemat ma cennik PIM?                   → wyjątek
4. GPSR / wymogi prawne rynku?              → wyjątek
   ── dalej per produkt ──
5. Produkt ma już ofertę na tym koncie?     → SKIPPED (automat nie powiela)
6. Cena z cennika > 0?                      → FAILED
7. Automat zbudował parametry?              → FAILED gdy brak WYMAGANYCH
8. POST → id oferty
9. Weryfikacja rynku → validation_status
10. Zapis do tabeli ofert (updateOrCreate)
```

Zwraca **trzy kubełki**: `published` / `failed` / `skipped` — każdy z powodem. To jest różnica
między „poszło 40 z 50" a „poszło 40, 7 bez ceny, 3 już były". Przenosimy.

**Bezpieczniki warte skopiowania co do joty:**
- `MAX_BATCH = 50` — publikacja idzie w cyklu requestu, nie w kolejce.
- Oferta powstaje **zawsze jako szkic**, chyba że schemat ma `publication_mode = active`.
- Słowniki konta (GPSR, warunki) pobierane **raz na przebieg**, cache 10 min — inaczej 4 zapytania na każdą porcję.
- Cena: `ceil(netto × mnożnik × (1+VAT))` — do pełnych złotych, konwencja z pliku wymiany BaseLinkera.

---

## 5. Operacje masowe (`AllegroOfferController`) — 4 sztuki

| Operacja | Mechanika Allegro | Odpowiednik eBay |
|---|---|---|
| `bulkChangePrice` | command pattern z UUID, batch | mamy `revisePrice` (per pozycja) |
| `bulkChangeQuantity` | j.w. | mamy `reviseQuantity` |
| `bulkPublication` ACTIVE/ENDED | per oferta | **brak — do zbudowania** (`EndItem`/`ReviseItem`) |
| `bulkUpdateDescription` | render szablonu z bieżącego stanu PIM → push | **brak — do zbudowania** |

### Trzy wzorce z tego pliku, które są ważniejsze niż same operacje

**1. `runPerAccount()`** — zaznaczone oferty grupowane po koncie, osobny klient/token per konto.
U nas odpowiednik to **grupowanie po `marketplace`** (DE/FR/ES mają osobne kategorie i nazwy pól —
to już wiemy z `ebay:ktype-push`).

**2. Nie zakładaj sukcesu — odczytaj wynik.** Cytat z kodu:

> Allegro potrafi przyjąć żądanie i zostawić stary status (np. gdy oferta nie przeszła
> weryfikacji) — bierzemy stan z odpowiedzi zamiast zakładać sukces.

Nasz `setQuantity` dziś robi dokładnie to, przed czym ten komentarz ostrzega: wysyła, po czym
`forceFill(['quantity' => …])->save()` bez sprawdzenia, co rynek faktycznie ustawił.

**3. `humanizeAllegroError()`** — wyciąga `userMessage` z JSON-a błędu zamiast pokazywać surowy
dump. Nasz `EbaySellClient::call()` rzuca `LongMessage`, więc pół roboty mamy.

---

## 6. Ekran „Wystawianie" (`AllegroListingController::index`)

Lista produktów PIM z filtrami przeniesionymi **1:1 z działu Produkty** (źródło, aktywny,
kategorie z poddrzewem nested-set) plus dwa własne:

- `listed` — wystawione / niewystawione (po istnieniu oferty)
- `priced` — **gotowość cenowa**: czy produkt ma cenę w cenniku schematu

Ten drugi to lekcja z wdrożenia (commit „widoczna gotowosc cenowa"): brak ceny widać **przed**
zaznaczeniem, a nie jako błąd po kliknięciu „Wystaw". Kolumna pokazuje cenę brutto policzoną
dokładnie tak, jak policzy ją publikacja.

Plus `bulk_select_all` — „zaznacz wszystkie na wszystkich stronach" zwraca same ID wg filtra.

Dwa kroki przed wysyłką: **`publishPreview`** (co automat złoży + `missing_for_publish`) → dopiero
potem **`publish`**.

---

## 7. Co z tego mamy, a czego nie

| Element wzorca | Stan u nas |
|---|---|
| OAuth + tokeny szyfrowane | ✅ `EbayOAuthService` + `EbaySettings` |
| Klient HTTP rynku | ✅ `EbaySellClient` (Trading XML) + `EbayTaxonomyClient` |
| Lustro ofert | ✅ `ebay_offers` + `EbayOfferService::syncActiveListings` |
| Mapowanie oferta ↔ produkt po SKU | ✅ `matchBySku` / `applyAutoAssign` |
| Operacje masowe cena/ilość | 🟡 są, ale bez `runPerAccount` i bez odczytu wyniku |
| Kategorie + parametry kategorii | 🟡 `EbayTaxonomyClient` umie kompatybilność pojazdów; **brak** `getItemAspectsForCategory` i brak tabeli „nauczonych" kategorii |
| Szablony aukcji | ❌ brak |
| Schematy | ❌ brak |
| Automat parametrów (DraftBuilder) | ❌ brak |
| Publikacja | ❌ brak — **rdzeń zadania** |
| Ekran Wystawianie | ❌ brak |
| Zakończ / aktywuj / masowy opis | ❌ brak |

**Nasza przewaga nad startem Allegro:** mamy już działające lustro ~3338 ofert, mapowanie po SKU,
multi-rynek (DE/FR/ES) i `ebay:ktype-push` z fitmentem. Allegro zaczynało od zera.

**Nasz dług:** wszystko siedzi w jednym `EbayOfferService`; nie ma podziału na sync i listing.

---

## 8. Rozwidlenie Trading vs Inventory — rozstrzygnięte

Wzorzec Allegro jest **ofertocentryczny ze szkicem**: `publication.status = INACTIVE` → weryfikacja
→ aktywacja. Po stronie eBay taki cykl daje **Inventory API** (`inventory item` → `offer` →
`publishOffer`, plus `withdrawOffer`). Trading `AddFixedPriceItem` nie zna pojęcia szkicu.

Ale nasze 3338 aukcji chodzi na Trading — i tam zostaje. **Hybryda:**

- **nowe wystawianie** → Inventory API (szkic → weryfikacja → aktywacja, jak Allegro),
- **istniejące aukcje** → Trading, bez zmian (ilość, cena, `ktype-push`).

Koszt: fitment dla nowych ofert pójdzie inną ścieżką niż `ReviseFixedPriceItem`. Do potwierdzenia
w dokumentacji eBay przy implementacji — nie sprawdzone w kodzie.

---

## 9. Kolejność budowy (etapy z OMS, przełożone na nas)

W OMS kolejność była: kategorie → szablony → schematy → wystawianie → operacje masowe. Każdy etap
dawał coś działającego. Proponuję tę samą, bo każdy kolejny element opiera się na poprzednim:

- **A — Kategorie i parametry.** `getItemAspectsForCategory` w `EbayTaxonomyClient` + tabela
  `ebay_categories` (`aspects`, `aspect_map`) + ekran. *Efekt: wiadomo, czego eBay wymaga.*
- **B — Szablony aukcji.** Tabela + renderer + edytor. *Efekt: opis składa się z danych PIM.*
- **C — Schematy.** Tabela + CRUD. *Efekt: przepis wystawiania gotowy.*
- **D — Ekran Wystawianie + podgląd.** Lista z gotowością cenową, multi-select, `publishPreview`.
  *Efekt: widać co i za ile poleci — bez wysyłki.*
- **E — Publikacja.** `EbayOfferPublishService` + Inventory API, szkice. *Efekt: pierwsza oferta.*
- **F — Operacje masowe.** Zakończ/aktywuj + masowy opis + poprawka istniejących (`runPerAccount`,
  odczyt wyniku). *Efekt: zarządzanie skalą.*

---

## 10. Rozstrzygnięcia (2026-08-18)

**1. Schemat per rynek — POTWIERDZONE danymi z API.** Sonda Taxonomy pokazała, że ta sama
półka towaru ma na każdym rynku inne drzewo, inne ID i inne nazwy aspektów:

| Rynek | Drzewo | Kategoria | Aspektów | Wymagane |
|---|---|---|---|---|
| EBAY_DE | 77 | 14769 (`…Karosserie-, Anbauteile & Zubehör → Sonstige`) | 13 | `Hersteller` |
| EBAY_FR | 71 | 9886 | 21 | `Marque`, `Numéro de pièce fabricant` |

Dochodzi do tego VAT per rynek (DE 19 / FR 20 / ES 21) i osobny cennik. Jeden schemat tego nie
uniesie — **schemat niesie `marketplace`**, a kategorie żyją w kluczu `(marketplace, category_id)`.

Dobra wiadomość: wymogów jest dużo mniej niż w Allegro (tam 6 wymaganych, w tym słownik 5660
pozycji). `Hersteller` to u nas stała, `Herstellernummer` to `product_code`.

**2. Tłumaczenia — rozwiązują się przez punkt 1.** Skoro schemat niesie rynek, to rynek wyznacza
locale (`EBAY_DE → de`, `EBAY_FR → fr`), a renderer szablonu i tytuł czytają ten slot z matrycy.
Bez nowych tabel. To jedyne miejsce, gdzie wychodzimy poza wzorzec OMS (Allegro = jeden język).

**3. EPR/GPSR nie blokuje budowy, blokuje aktywację.** Oferty i tak powstają jako **szkice**
(`publication_mode = draft`), więc kod powstaje niezależnie. Ale patrz [[epr_opakowania_status]] —
brak numerów LUCID/IDU/Ecoembes może uniemożliwić *aktywację* na DE/FR/ES. Do rozstrzygnięcia
biznesowo przed etapem E, nie przed A.

**4. Zdjęcia — do sprawdzenia w etapie E.** Allegro przyjmuje zewnętrzne URL-e; eBay również
powinien (Trading `PictureURL`, Inventory `imageUrls`), ale wymaga publicznie osiągalnych adresów.
Nie sprawdzone — weryfikacja przy pierwszej realnej publikacji.

---

## 11. Stan budowy

### ✅ Etap A — backend (2026-08-18)

| Plik | Co robi |
|---|---|
| `EbayTaxonomyClient::itemAspectsForCategory()` | aspekty kategorii (Item Specifics), cache 7 dni |
| `EbayTaxonomyClient::categorySuggestions()` | wyszukiwarka kategorii po nazwie (ze ścieżką) |
| `ebay_categories` (migracja) | kategoria per rynek + `aspects` + `aspect_map` |
| `App\Models\Ebay\EbayCategory` | `unmappedRequired()`, `isReadyForListing()` |
| `ebay:category-info` | szukaj / pokaż wymogi / `--save` = naucz kategorię |

Zweryfikowane na żywym API (token aplikacyjny, **bez OAuth usera**): wyszukiwarka trafia w naszą
kategorię DE, obie kategorie (DE 14769, FR 9886) nauczone i zapisane, bramka `isReadyForListing()`
odrzuca wpis ze źródłem zadeklarowanym, ale pustym.

### ✅ Etap A — ekran „Kategorie i parametry" (2026-08-18)

| Plik | Co robi |
|---|---|
| `Connect\Marketplace\EbayCategoryController` | index / search / activate / updateMapping / refresh / destroy |
| `routes/crafter.php` (+6 tras) | `connect/marketplace/ebay/categories/*` |
| `Pages/Connect/Marketplace/Ebay/Categories/Index.vue` | wyszukiwarka + lista nauczonych + edytor mapowania |
| `Components/Sidebar.vue` | Marketplace → „Ebay · Kategorie" (obok „Ebay · Aukcje") |

**Namespace tras — zgodnie z OMS.** Tam `connect/integrations/allegro` = ustawienia i OAuth,
`connect/marketplace/allegro/*` = ekrany robocze. Nasze `connect/integrations/ebay` (ustawienia +
OAuth) jest więc pod właściwym adresem; nowe ekrany idą pod `connect/marketplace/ebay/*`.
⚠️ Wyjątkiem jest istniejący ekran ofert — siedzi pod `integrations/ebay/offers`, a docelowo
należy do `marketplace/ebay/offers`. Przeniesienie ruszy trasy, sidebar i `Offers.vue`, więc to
osobne zadanie, nie doklejka do etapu A.

Zweryfikowane wywołaniami kontrolera na żywym API:
- wyszukiwanie po frazie (FR: „protection moteur" → 10 trafień z nazwami francuskimi),
- wyszukiwanie po ID kategorii **nie będącej liściem** → czytelny błąd z eBaya, zero wyników,
- aktywacja kategorii na trzecim rynku (ES) → 14 aspektów, 1 wymagany,
- `cleanMap()` odrzuca wpisy niekompletne (stała ze spacjami, atrybut bez `attribute_id`),
  zostawiając tylko kompletne — bez tego bramka gotowości przepuściłaby kategorię, której eBay
  i tak by nie przyjął. Po zmapowaniu `Hersteller` → stała `BSP` kategoria DE jest **gotowa**.

Front zbudowany (`npm run build`), strona w manifeście.

### ✅ Etap B — treść oferty (2026-08-18) · **odstępstwo od wzorca**

Wzorzec Allegro ma własny byt `AllegroListingTemplate` (sekcje, tagi `[opis]`, osobny edytor).
**Nie odtwarzamy go** — i to nie z lenistwa, tylko dlatego, że u nas nie miałby z czego czerpać:

| Pole | pl | de | fr | es |
|---|---|---|---|---|
| `name` | 1494 | 1494 | 1494 | 1494 |
| `info_1` (opis) | **7** | **4** | **4** | **0** |
| `info_2` / `info_3` | 0 | 0 | 0 | 0 |

Opisy w PIM praktycznie nie istnieją — tagi `[opis]`/`[opis_dodatkowy*]` z wzorca byłyby puste.
Za to feed produkcyjny ma opis dla **1627/1627** produktów, bo opis jest **generowany**: tabela
`templates` (jeden wiersz na locale) renderowana Bladem przez `Product::getVariables($locale)`
zasila Selly, PrestaShop i OpenCart. Mamy 12 gotowych szablonów, w tym `oslonyparetode` (de),
`oslonyparetofr` (fr), `bsp-es` (es).

**Decyzja: eBay korzysta z tych samych szablonów.** Zyski: jedno źródło treści (aukcja mówi to
samo co sklep), wielojęzyczność za darmo przez `Template.locale` — czyli znika największa różnica
między nami a OMS — i **zero nowych ekranów**, bo `admin/templates` ma już CRUD i podgląd.

| Plik | Co robi |
|---|---|
| `Services\Ebay\Listing\EbayListingRenderer` | tytuł (limit 80 zn., cięcie na granicy słowa), opis (biała lista HTML), zdjęcia (max 24), `problems()` |
| `ebay:render-preview` | podgląd jednego produktu albo `--audit` całego katalogu |

### 📊 Audyt katalogu (1494 produkty, przed jakąkolwiek wysyłką)

| Rynek | Bez zastrzeżeń | Tytuł > 80 zn. | Brak zdjęć |
|---|---|---|---|
| DE (`oslonyparetode`) | 1372 | 86 | 37 |
| FR (`oslonyparetofr`) | 1099 | **365** | 37 |
| ES (`bsp-es`) | 1430 | 28 | 37 |

**FR wymaga skrócenia tytułu w szablonie** — 365 tytułów (24 %) przekracza limit eBaya, bo
francuska nazwa jest długa („Acier plaque couvercle cache protection moteur…"). Renderer przycina
je na granicy słowa, ale lepszy tytuł powstaje w szablonie niż z obcięcia.

**37 produktów bez zdjęć** — ta sama trzydziestka siódemka na każdym rynku, luka w katalogu.

### 🐛 Błędy w istniejących szablonach (poszłyby wprost na aukcje)

1. **Wartości atrybutów nie są tłumaczone.** W opisie DE, FR i ES stoi polskie
   `silnik, skrzynię biegów, Radiator` — `getVariables()` bierze `AttributeValue.name` bez locale.
2. **`oslonyparetode` kończy tytuł sierotą `EAN`** — „…Mito (2008-2018) EAN".
3. **`bsp-es` wtrąca niemiecki** — „Material de protección: **Stahl (sehr robust und flexibel)**".
4. **`Schutzdicke: 0.00 mm`** — `products.width` jest zerowe.

Dotyczy to również sklepów i Selly, nie tylko eBaya — to nie jest dług tego modułu.

### ✅ Etap C — schematy (2026-08-18)

| Plik | Co robi |
|---|---|
| `ebay_schemes` (migracja) | rynek + kategoria + szablon + cennik + polityki + tryb publikacji |
| `App\Models\Ebay\EbayScheme` | `locale()`, `grossPrice()`, `problems()`, `isReady()` |
| `Connect\Marketplace\EbaySchemeController` | index / store / update / destroy |
| `Pages/…/Ebay/Schemes/Index.vue` | lista z edycją w miejscu, VAT domyślny per rynek |
| `routes/crafter.php` (+4 trasy), `Sidebar.vue` | „Ebay · Schematy" |

`marketplace` siedzi na schemacie, a nie jest wyprowadzany z kategorii, bo rządzi trzema rzeczami
naraz: kategorią, locale szablonu i stawką VAT.

**Pięć bramek `problems()` — każda sprawdzona:**

| Przypadek | Wynik |
|---|---|
| komplet DE | gotowy |
| kategoria z rynku FR w schemacie DE | „kategoria jest z rynku EBAY_FR, a schemat z EBAY_DE" |
| szablon `fr` na rynku DE | „szablon jest w locale «fr», a rynek EBAY_DE oczekuje «de»" |
| kategoria z niezmapowanymi aspektami | „niezmapowane: Marque, Numéro de pièce fabricant" |
| brak cennika | „brak cennika PIM (źródło ceny)" |

Cena: 100,00 netto × 1,15 × VAT 20 % = **138,00** brutto.

**Świadomie NIE blokujemy** braku polityk eBay (fulfillment/payment/return) ani lokalizacji —
są wymagane dopiero przy AKTYWACJI, a szkic przejdzie bez nich. Blokowanie zabiłoby sens szkiców:
złożyć ofertę i obejrzeć ją, zanim domknie się konfigurację konta. Kolumny są, czekają na etap E.

Zapis niekompletnego schematu jest dozwolony (buduje się go etapami), ale komunikat od razu mówi,
czego brakuje.

### ✅ Etapy D + E — wystawianie i publikacja (2026-08-18)

| Plik | Co robi |
|---|---|
| `Services\Ebay\EbayInventoryClient` | REST: `inventory_item` → `offer` → `publish`/`withdraw`, polityki, lokalizacje |
| `Listing\EbayOfferDraftBuilder` | produkt + schemat → tytuł, opis, zdjęcia, aspekty, `notes` |
| `Listing\EbayOfferPublishService` | orkiestracja, trzy kubełki, limit 50/porcję |
| `Marketplace\EbayListingController` | lista + `publishPreview` + `publish` |
| `Pages/…/Ebay/Listing/Index.vue` | filtry, gotowość cenowa, multi-select, modal podglądu i wyniku |

**Bezpieczniki (wprost z wzorca):**
- oferta powstaje jako **szkic**; `publishOffer()` leci tylko przy `publication_mode = active`,
- tryb `active` bez polityk i lokalizacji → wyjątek z listą braków, zamiast błędu z eBaya,
- produkt z ofertą na tym rynku → `skipped`, automat nie powiela aukcji,
- ponowna próba po błędzie używa istniejącej oferty dla SKU zamiast tworzyć duplikat,
- `MAX_BATCH = 50` — publikacja idzie w cyklu requestu.

Szkice trafiają do `ebay_offers` z `item_id = draft:{offerId}`, żeby nie zderzyć się
z prawdziwymi ItemID starych aukcji z Trading.

**Sprawdzone lokalnie** (ścieżka podglądu nie dotyka eBaya): automat złożył oferty dla trzech
produktów — tytuły 62–63 znaki, aspekty `{"Hersteller":["BSP"],"Herstellernummer":["07.043"]}`,
4–5 zdjęć, zero uwag. Po skasowaniu mapowania aspektu blokada zadziałała:
„WYMAGANY aspekt «Hersteller» nie ma źródła w mapowaniu kategorii".

**NIE sprawdzone** — wymaga produkcji: samo `POST /sell/inventory/*`. Lokalnie nie ma OAuth
user-tokena ani publicznych adresów zdjęć (MediaLibrary zwraca `http://pim.test/...`).

### ⏭️ Dalej

- **F** — operacje masowe: zakończ/aktywuj, masowa aktualizacja opisu, `runPerAccount`
  (u nas: grupowanie po rynku) i odczyt faktycznego wyniku zamiast zakładania sukcesu.
- ~~Wybór polityk w UI~~ — **zrobione**, patrz niżej.
- *(osobno)* przeniesienie ekranu ofert pod `marketplace/ebay/offers`.
- *(osobno)* poprawki szablonów: tytuł FR, tłumaczenie wartości atrybutów, sierota `EAN`, `bsp-es`.
- *(osobno)* `EbayOffersController::setQuantity` — zapisuje żądaną ilość bez odczytu wyniku.

### ✅ Polityki eBay w schemacie (2026-08-18)

`EbaySchemeController::policies` (POST, per rynek) → `EbayInventoryClient::businessPolicies()`
+ `inventoryLocations()`. W schemacie doszła sekcja „Polityki eBay i lokalizacja" z przyciskiem
„Pobierz z konta eBay" i czterema polami: dostawa / płatności / zwroty / lokalizacja.

Zachowanie bez OAuth (tak jest lokalnie): endpoint zwraca **200** z komunikatem i pustymi listami,
a pola spadają na wpisywanie ID ręcznie. Lepsze niż błąd — konfiguracja schematu nie blokuje się
o niepołączone konto.

Ostrzeżenie przy trybie `active` liczy braki **na żywo z formularza**: dopóki któregoś ID nie ma,
ramka jest czerwona i mówi czego brakuje, zamiast obiecywać publikację, która i tak by nie przeszła.

### 🚀 Pierwsze uruchomienie na produkcji

1. Migracje wchodzą same (auto-deploy) — obie to `CREATE TABLE`, nic nie ruszają.
2. **Kategorie**: Marketplace → Ebay · Kategorie → szukaj → Aktywuj → zmapuj wymagane aspekty
   (DE: `Hersteller`; FR: `Marque` + `Numéro de pièce fabricant`).
3. **Schemat**: rynek + kategoria + szablon w zgodnym locale + cennik. Zostaw tryb **szkic**.
4. **Wystawianie**: zaznacz **jeden** produkt → Podgląd → Wystaw. Sprawdź szkic w panelu eBay.
5. Dopiero po obejrzeniu szkicu dokładaj polityki i rozważ tryb `active`.

⚠️ Aktywacja ofert na DE/FR/ES może być zablokowana brakiem numerów EPR — patrz §10 pkt 3.

### 🐛 Znalezione przy okazji

`EbayOffersController::setQuantity` zapisuje żądaną ilość bez sprawdzenia, co eBay faktycznie
ustawił — przy odrzuconej zmianie PIM pokazuje stan, którego na rynku nie ma. Wzorzec poprawki:
`AllegroOfferController::bulkPublication` (odczyt statusu z odpowiedzi).
