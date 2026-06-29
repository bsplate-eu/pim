# Analiza integracji „Sumpguard" w PIM

> Data analizy: 2026-05-20
> Pytanie: *Czy integracja Sumpguard w PIM jest aktywna i czy dodaje nowe produkty do bazy?*
> Źródło prawdy: kod aplikacji + najświeższe backupy bazy (`+importy/backup-przed-connectors-20260520_104330.sql` z dziś oraz `baza danych/backup-pre-argohq-20260519-125607.sql` z wczoraj). Bezpośredni dostęp do żywej bazy nie był możliwy z powłoki (brak `php`/`mysql` na PATH).

---

## Werdykt

| Pytanie | Odpowiedź |
|---|---|
| Czy integracja jest aktywna? | **TAK** — `enabled = 1`, spięta ze schedulerem, klasa istnieje |
| Czy dodaje nowe produkty do bazy? | **TAK** — ale nowe produkty powstają jako **wyłączone** (`enabled = false`) |

---

## 1. Czy aktywna? — TAK

- Wpis w tabeli `sources` (backup z 2026-05-20):
  ```sql
  INSERT INTO `sources` VALUES (1,'Sumpguard','SumpguardSource',NULL,1,0,'2025-01-23 15:48:20','2025-01-23 15:50:30');
  ```
  Kolumny: `id=1`, `name='Sumpguard'`, `service_class='SumpguardSource'`, `options=NULL`, **`enabled=1`**, `order=0`, `created_at`, `updated_at`.
- Uruchamiana automatycznie **codziennie o 01:00** przez scheduler.
- Klasa `App\Sources\SumpguardSource` istnieje, więc ścieżka wykonania jest kompletna.

### Łańcuch wywołań (jak to się odpala)

```
Scheduler (cron: schedule:run co minutę)
  └─ app/Console/Kernel.php:20
       $schedule->command('sources:sync')->dailyAt('01:00');
          └─ app/Console/Commands/SourcesSync.php:35
               Source::enabled()->get()->each(fn($s) => SynchronizeSource::dispatchSync($s->id))
                  └─ app/Jobs/SynchronizeSource.php:37
                       $source->synchronize()
                          └─ app/Models/Source.php:22-31
                               $class = 'App\Sources\' . $service_class;  // => App\Sources\SumpguardSource
                               (new $class($this))->synchronize();
                                  └─ app/Sources/SumpguardSource.php:104  synchronize()
```

Uwagi:
- `Source::enabled()` (scope w `app/Models/Source.php:17`) filtruje tylko `enabled = true` — Sumpguard ma `1`, więc się łapie.
- `sources:sync` używa `dispatchSync` (synchronicznie), więc dla samego Sumpguarda **worker kolejki nie jest nawet potrzebny**. Wymagany jest jedynie działający cron schedulera na serwerze.

---

## 2. Czy dodaje nowe produkty? — TAK (jako wyłączone)

Logika w `app/Sources/SumpguardSource.php`:

### `synchronize()` (linie 104-125)
1. `synchronizeAttributes()` — tworzy brakujące atrybuty (`make`, `model`, `year_start`, `year_stop`, `oil`, `engine`, `gearbox`, `protection`).
2. Pobiera tłumaczenia dla wszystkich `available_locales`.
3. `getProducts()` — tworzy/aktualizuje produkty.
4. `getPrices()` — zapisuje ceny.
5. `compare()` — wykrywa zmiany i wysyła maila.

### `getProducts()` (linie 182-215) — **tu powstają nowe produkty**
Dla każdego rekordu z feedu:
```php
$product = $this->products->get($item['id']);   // szukanie po external_id
if ($product) {
    $product->update($data);                      // istnieje -> UPDATE
} else {
    $product = Product::create(array_merge($data, ['enabled' => false]));  // NIE istnieje -> CREATE (wyłączony!)
    $this->getImages($product, $item);
    $category = $this->getCategory($item);
    $product->categories()->sync([$category->id, $category->parent_id]);
}
$product->attributeValues()->sync($this->getAttributes($item));
```
- **Nowy produkt = `Product::create([... , 'enabled' => false])`** (linia 204) — pojawia się w bazie, ale **domyślnie wyłączony**; trzeba go ręcznie włączyć.
- Dopasowanie istniejących odbywa się po `external_id` (= `id` z feedu Sumpguard).

### Dane z feedu
- Źródło: `https://pl.sump-guard.co.uk/api/products/json/{locale}` (zapis do `storage/app/sumpguard/{locale}.json`).
- Mapowane pola produktu: `external_id`, `category` (`category/sub_category`), `name` (i18n), `product_code`, `width`, `weight`, `comment`.
- Podmiana marki: `Vauxhall` -> `Opel` (`vauxhallClear()`).

### Efekty uboczne (też zapisywane do bazy)
- **Kategorie** (`getCategory()`, linie 227-256): root „Sumpguard" + drzewo `category` -> `sub_category`, tworzone przez `firstOrCreate`.
- **Atrybuty i wartości atrybutów** (`getAttributes()`, linie 311-365): tworzone przez `firstOrCreate`.
- **Ceny** (`getPrices()`, linie 258-283): cennik o slugu `sumpguard` (waluta EUR), cena z pola `eur_alek`, zapis przez `PricelistProduct::upsert`.
- **Mail z różnicami** (`compare()`, linie 368-426): jeśli są zmiany lub nowości, wysyłka na `info@bsplate.eu` oraz `a.sliwinski@argosolutions.pl` (klasa `App\Mail\SumpguardEmail`, widok `resources/views/email/sumpguard.blade.php`). Historia JSON trzymana w `storage/app/sumpguard/history/` (`current.json`, `prev.json`, `{data}.json`).

---

## 3. Ważny niuans: dwie implementacje Sumpguard

| | `App\Sources\SumpguardSource` | `App\Services\SumpguardService` |
|---|---|---|
| Status | **AKTYWNA** | legacy / nieużywana w automacie |
| Uruchamiana przez | scheduler `sources:sync` (codziennie 01:00) | ręczna komenda `sumpguard:update` |
| W schedulerze? | TAK | **NIE** |
| Zapis produktów | `Product::create/update` (po `external_id`) | `Product::upsert` na innym schemacie |
| Schemat `products` | aktualny (`external_id`, `category`, `name`, `product_code`, `width`, `weight`, `comment`) | stary (`secondary_name`, `oil`, `engine`, `gearbox`, `images`, `protection`…) |

- Migracja `database/migrations/2024_10_26_090025_create_sources_table.php:23` pierwotnie seedowała `service_class='SumpguardService'`, ale w żywej bazie wartość poprawiono na **`'SumpguardSource'`** — dlatego realnie działa ścieżka `Source`, nie `Service`.
- Komenda `app/Console/Commands/SumpguardUpdate.php` (`sumpguard:update`) wywołuje starą `SumpguardService::import()` i służy najwyżej do ręcznego, awaryjnego uruchomienia — nie jest częścią harmonogramu.

---

## 4. Kluczowe pliki

| Plik | Rola |
|---|---|
| `app/Console/Kernel.php:20` | Harmonogram: `sources:sync` codziennie o 01:00 |
| `app/Console/Commands/SourcesSync.php` | Iteruje włączone źródła, dispatch `SynchronizeSource` |
| `app/Jobs/SynchronizeSource.php` | Job wywołujący `$source->synchronize()` |
| `app/Models/Source.php` | Buduje `App\Sources\{service_class}` i woła `synchronize()`; scope `enabled()` |
| `app/Sources/SumpguardSource.php` | **AKTYWNA** logika importu (produkty, kategorie, atrybuty, ceny, mail) |
| `app/Services/SumpguardService.php` | Legacy implementacja (komenda `sumpguard:update`) |
| `app/Console/Commands/SumpguardUpdate.php` | Ręczna komenda `sumpguard:update` (legacy, poza schedulerem) |
| `app/Mail/SumpguardEmail.php` + `resources/views/email/sumpguard.blade.php` | Mail „Sumpguard Zmiany JSON" |
| `database/migrations/2024_10_26_090025_create_sources_table.php` | Tabela `sources` + seed wpisu Sumpguard |
| `database/migrations/2026_05_08_120000_add_order_to_sources_table.php` | Dodanie kolumny `order` (stąd dodatkowe `0` w nowszych backupach) |
| `storage/app/sumpguard/` | Pobrane JSON-y feedu + katalog `history/` |

---

## 5. Jak potwierdzić na żywej bazie

W katalogu projektu (`D:\laragon\www\PIM`), używając PHP z Laragona:

```bash
# Czy źródło włączone:
php artisan tinker --execute="echo App\Models\Source::where('name','Sumpguard')->value('enabled');"

# Ile produktów ma przypisane źródło Sumpguard i kiedy dodano ostatni:
php artisan tinker --execute="\$s=App\Models\Source::where('name','Sumpguard')->first(); echo App\Models\Product::where('source_id',\$s->id)->count().' szt.; ostatni: '.App\Models\Product::where('source_id',\$s->id)->max('created_at');"
```

> Uwaga: w tym środowisku `php` i `mysql` nie były dostępne na PATH — powyższe trzeba odpalić z terminala Laragona (gdzie binarki są w PATH).

---

## 6. Podsumowanie

1. **Integracja Sumpguard jest aktywna** — `enabled=1`, spięta ze schedulerem, uruchamiana codziennie o 01:00 (pod warunkiem działającego crona schedulera na serwerze).
2. **Dodaje nowe produkty do bazy** — przez `Product::create(... , 'enabled' => false)`; nowe produkty są **wyłączone** i wymagają ręcznego włączenia. Istniejące (po `external_id`) są aktualizowane.
3. Tworzy także kategorie, atrybuty, wartości atrybutów i ceny (cennik `sumpguard`, EUR), oraz wysyła mail z różnicami/nowościami.
4. Działa **nowa** ścieżka `App\Sources\SumpguardSource`; stara `App\Services\SumpguardService` (komenda `sumpguard:update`) jest legacy i poza harmonogramem.
