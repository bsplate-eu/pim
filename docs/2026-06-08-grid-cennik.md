# Grid cennika — funkcje

**Strony:**
- Lista: `/admin/pricelists` ([Pricelist/Index.vue](../resources/js/crafter/Pages/Pricelist/Index.vue))
- Edycja: `/admin/pricelists/edit/{id}` ([Pricelist/Edit.vue](../resources/js/crafter/Pages/Pricelist/Edit.vue) + [Form.vue](../resources/js/crafter/Pages/Pricelist/Form.vue))

## Lista cenników (Index.vue)

Trzy akcje per wiersz: **Edit** → **Stwórz kopię** → **Delete**.

### Stwórz kopię
Ikona „dokument-duplikat" otwiera modal z potwierdzeniem. Po akceptacji:
- Tworzy nowy `Pricelist` z nazwą `"{nazwa} (kopia)"` (przy kolizji: `(kopia 2)`, `(kopia 3)`, …), własnym unikalnym slugiem, identyczną walutą.
- Kopiuje **wszystkie wiersze** `pricelist_product` (chunki po 1000).
- Przekierowuje na edycję nowego cennika.

Backend: [`PricelistController::clone`](../app/Http/Controllers/Admin/PricelistController.php).
Trasa: `POST /admin/pricelists/{pricelist}/clone` (permission: `crafter.pricelist.create`).

## Edycja cennika

Nagłówek strony (sticky) ma **trzy przyciski**:

| Przycisk | Akcja |
|---|---|
| **Aktualizuj z CSV** | Otwiera file picker → upload CSV → `POST /admin/pricelists/{id}/import-csv` → upsert do `pricelist_product` |
| **Eksport CSV** | `GET /admin/pricelists/{id}/export-csv` — pobiera plik w formacie identycznym jak stary arkusz Google |
| **Save** | `PUT /admin/pricelists/{id}` — zapisuje name + currency + wiersze cen z gridu |

### Wyszukiwarka (nad gridem)

Cztery pola, filtr klient-side (in-memory, bez zapytań do bazy):

| Pole | Działanie |
|---|---|
| **Kod** | fragment `product_code` (case-insensitive) |
| **Nazwa** | fragment `name` (case-insensitive) |
| **Cena od** / **Cena do** | zakres numeryczny |

Pola łączą się przez **AND**. Każde z `clearable` (krzyżyk). Filtr przelicza się tylko gdy zmienia się wartość pola (NIE przy edycji komórki gridu) — patrz `shallowRef` w `DataGrid`.

### Grid (DataGrid)

Kolumny:
- ☐ (checkbox selekcji, pinowana po lewej)
- **Kod** (read-only, sortable)
- **Nazwa** (read-only, sortable)
- **Cena sprzedaży netto** (`price`, editable, sortable — cena **właściwa** produktu)
- **Cena netto aut.** (`auto_price`, editable, sortable — wynik Operacji masowych; cena właściwa zostaje nietknięta)
- **Cena ręczna** (`manual_price`, editable, sortable — twardy override; gdy > 0 jest **ceną eksportową**)
- **Cena zak EUR** (`purchase_price`, read-only — z cennika bazowego, slug `sumpguard`, EUR)
- **Zysk** / **Marża** (liczone z **ceny eksportowej** = `manual_price` ?? `price`, i ceny zakupu)

#### Sortowanie
- Każdy nagłówek z sortable ma widoczną strzałkę **⇅** (przyciemniona).
- Klik: rosnąco ↑ → malejąco ↓ → bez sortowania ⇅.
- Cena sortuje numerycznie (`cellCompare: numericCompare`) — `7` przed `22`.

#### Zaznaczanie
- Checkbox w każdym wierszu.
- Checkbox w **nagłówku** = zaznacz / odznacz wszystkie aktualnie widoczne (po filtrze).
- Licznik nad gridem: `Zaznaczonych: X / Widocznych: Y / Wszystkich: Z`.

#### Wysokość
Tryb `height="auto"` — grid ma wysokość proporcjonalną do liczby widocznych wierszy. Brak wewnętrznego suwaka, strona scrolluje się normalnie. Po filtrze grid zwija się do trafień.

#### Edycja komórek
- Dwuklik / Enter → tryb edycji (domyślny tekstowy edytor RevoGrid).
- Wklejanie z Excela (Ctrl+V po zaznaczeniu zakresu).
- Każda zmiana mutuje `form.rows` przez `update:modelValue`.
- `keyField="product_id"` — edycja po sortowaniu trafia do właściwego produktu, nie do widocznego indeksu.

## Operacje masowe (modal)

Przycisk **Operacje masowe** nad gridem. **Wszystkie operacje liczące piszą do kolumny
`auto_price` („Cena netto aut.") — nigdy do ceny właściwej (`price`).** Cenę właściwą
ustawia się ręcznie w gridzie albo przyciskiem **Przepisz auto → cena właściwa**.

### Zakres operacji
**Jawny selektor (radio) na górze modala** — trzy tryby z licznikami na żywo:
- **Zaznaczone (N)** — produkty z checkboxami (radio aktywne tylko gdy N>0)
- **Widoczne po filtrze (N)**
- **Wszystkie (N)**

Przy otwarciu modala tryb ustawia się inteligentnie: **zaznaczone > filtr > wszystkie** (można nadpisać).
Można dodatkowo zawęzić przez **źródło** (sekcja „Zakres: źródło produktów") — działa jako ∩.
Selekcja **NIE jest czyszczona** po operacji → można łańcuchować na tych samych produktach
(np. *Wylicz z ceny zakupu* → *Przepisz auto → cena właściwa*). Przycisk „Operacje masowe"
pokazuje liczbę zaznaczonych. *Przelicz na walutę* jest globalne (ignoruje zakres).

### Wylicz ceny z ceny zakupu
- `auto_price = Cena zak EUR × mnożnik` (tryb `multiply`) lub `× procent/100` (tryb `percent`).
- Waluta docelowa ≠ EUR → wynik przeliczany z EUR kursem NBP; ustawia `form.currency`.
- Produkty bez ceny zakupu są pomijane. Wzór + tryb zapisują się na cenniku przy **Save**.

### Zmień cenę o procent
- Pole `%` (ujemna = obniż). Działa na `auto_price`.
- Klik **Zastosuj** → `nowa = round(auto * (1 + pct/100), 2)`.
- Po operacji selekcja jest czyszczona.

### Przelicz na walutę
- Dropdown `EUR / PLN / CZK` + **Przelicz**. Przelicza `auto_price`.
- Pobiera kursy z `GET /admin/exchange-rates/nbp?codes=...` (NBP tabela A, dzień poprzedni, cache 6h).
- Most PLN: `auto_PLN = auto_source × rate_source`, `auto_target = auto_PLN / rate_target`.
- Operacja jest **globalna** — wymaga zakresu „Wszystkie źródła" + zmienia `form.currency`.
- Po sukcesie pokazuje użyty kurs + datę publikacji NBP.

### Przepisz na cenę właściwą
- Przycisk **Przepisz auto → cena właściwa**: kopiuje `auto_price` → `price` w bieżącym zakresie.
- Jedyna operacja masowa, która **celowo** nadpisuje cenę właściwą (po akceptacji użytkownika).
- Po operacji selekcja jest czyszczona. Zapis przez **Save**.

> **Uwaga:** `auto_price` zapisuje się do `pricelist_product.auto_price` przez **Save** (PUT),
> ale **nie** wchodzi do CSV — eksport/import obejmują tylko `price` (cena właściwa, format jak niżej).

## CSV — format identyczny jak stary arkusz Google

### Eksport
`id, product_code, name, price`
- `id` = `product.id` (NIE `pricelist_product.id`)
- BOM UTF-8 (polskie znaki w Excelu bez ustawień)

### Import
- Parser auto-wykrywa BOM i separator (`,` lub `;`).
- Dopasowuje po kolumnie `id` (= `product.id`); `product_code`/`name` są w CSV tylko dla czytelności.
- Pusta cena → `0`. Polskie separatory (`,`) normalizowane.
- Walidacja: muszą być kolumny `id` i `price`.
- Limit pliku: 10 MB.

Backend: [`PricelistController::exportCsv`/`importCsv`](../app/Http/Controllers/Admin/PricelistController.php).

## Cena eksportowa (priorytet)

Cena wychodząca z cennika do **każdego** kanału jest rozwiązywana jako:

> **`manual_price` (Cena ręczna) jeśli > 0, inaczej `price` (Cena sprzedaży netto).**

`auto_price` nie jest eksportowana — wpada do `price` przyciskiem „Przepisz". Logika jest scentralizowana w
`PricelistProduct::EXPORT_PRICE_SQL` (`COALESCE(NULLIF(manual_price,0), price)`) i `PricelistProduct::exportPriceMap()`,
użytych we **wszystkich 8 ścieżkach eksportu**:

| Miejsce | Kanał |
|---|---|
| `PricelistController::exportCsv` | CSV |
| `SyncService` | sync PIM→sklep |
| `CatalogCreatePipeline` / `CatalogDeltaPipeline` | katalog integracji |
| `IntegrationProductController` | cena dla `integration_product` |
| `BaselinkerController` | BaseLinker |
| `PrestashopIntegrationProductsExport` / `SellyIntegrationProductsExport` | eksport plików |

> **Nowy konsument ceny cennika?** Użyj `PricelistProduct::exportPriceMap($pricelistId)` (mapa `product_id => cena`)
> albo `selectRaw(... PricelistProduct::EXPORT_PRICE_SQL ...)` — NIE surowego `pluck('price')`, bo ominie override.

## Endpointy

| Metoda | URL | Akcja | Permission |
|---|---|---|---|
| GET | `/admin/pricelists/edit/{id}` | Ekran edycji | `crafter.pricelist.edit` |
| PUT/PATCH | `/admin/pricelists/{id}` | Zapis (name, currency, rows) | `crafter.pricelist.edit` |
| GET | `/admin/pricelists/{id}/export-csv` | Pobranie CSV | `crafter.pricelist.edit` |
| POST | `/admin/pricelists/{id}/import-csv` | Upload CSV → upsert | `crafter.pricelist.edit` |
| POST | `/admin/pricelists/{id}/clone` | Klon cennika + wierszy | `crafter.pricelist.create` |
| GET | `/admin/exchange-rates/nbp?codes=EUR,CZK` | Kursy NBP (dzień poprzedni) | `crafter.pricelist.edit` |
