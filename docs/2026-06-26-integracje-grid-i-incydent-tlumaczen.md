# 2026-06-26 — Integracje: grid produktów (fix + kolumny) ORAZ incydent nadpisania tłumaczeń + odbudowa

Sesja obejmowała dwie rzeczy:
1. **Feature** — naprawa pustego gridu „Edit Integration Products" + dodanie kolumn PIM ID i ceny.
2. **Incydent** — w trakcie odkryto, że obce tłumaczenia (`de/cs/sk/fr/es`) zostały nadpisane polskim tekstem. Poniżej pełna recepta odbudowy, żeby **więcej do tego nie wracać**.

Środowisko: prod `pim.bsplate.eu` → `/home/admin/domains/pim.bsplate.eu/PIM/`, PHP `/usr/local/php83/bin/php`. Lokalnie Laragon, PHP `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe`, Node 18.

---

# CZĘŚĆ A — Grid produktów integracji

## Problem
`/admin/integrations/{id}/products` ("Edit Integration Products") renderował **same nagłówki kolumn, zero wierszy**, mimo że produkty były.

## Przyczyna (NIE backend — front)
- Backend (`IntegrationProductController@index`) zwracał komplet (zweryfikowane: prod miał 1606 `integration_products`, wszystkie z poprawnym produktem).
- `DataGrid.vue` (wrapper RevoGrid) z `height="70vh"` → **RevoGrid mierzy wysokość viewportu = 0 przy starcie → 0 wierszy** (nagłówki są, body puste).
- Jedyna sprawdzona ścieżka to **`height="auto"`** — DataGrid liczy wtedy konkretną wysokość w px ORAZ `frameSize=count+100`, więc wpycha wszystkie wiersze do DOM niezależnie od pomiaru. Dlatego Cennik (drugie użycie gridu) działał, a ten — nie. `height="70vh"` był użyty tylko tutaj = nieprzetestowana ścieżka.

## Co zrobiono
- `resources/js/crafter/Pages/IntegrationProduct/Form.vue`: `height="70vh"` → **`height="auto"`**.
- Dodano kolumnę **PIM ID** (`product_id`, pierwsza) — `product_id` już był w danych wiersza (to `keyField`), więc tylko kolumna we froncie.
- Dodano kolumnę **Cena (cennik)** — cena z cennika źródła integracji (`integration_source.pricelist_id` → `pricelist_product.price`). To dokładnie ta cena, którą zaciąga integracja (`IntegrationProduct::getBaselinkerProduct` używa tego samego `->price`).
  - Backend: `IntegrationProductController@index` — lookup ceny **bulk (bez N+1)**: zebrać `pricelist_id` ze źródeł + `product_id`, jedno `PricelistProduct::whereIn(...)->get()->keyBy("pricelist_id:product_id")`, potem `price` per wiersz. Kolumna read-only, nie wpływa na zapis nadpisań.
  - Typ: `resources/js/crafter/Pages/Integration/types.d.ts` — dodane `price` do `OverrideRow`.

## Wdrożenie
- Build: Node 18 (`export PATH="/c/laragon/bin/nodejs/node-v18:$PATH" && npm run build`).
- Paczka deploy: **PHP `ZipArchive`** (NIE PowerShell `Compress-Archive` — robi backslashe). Skrypt `.php` z `$zip->addFile($abs,$rel)`, `$rel` z separatorami `/`. Zawartość: kontroler PHP + `Form.vue` + `types.d.ts` + całe `public/build` (197 wpisów, 0 backslashy).
- Na prodzie: `unzip -o paczka.zip` (nadpisuje), `Ctrl+Shift+R`. Backend PHP łapie od razu; front z `public/build`.

---

# CZĘŚĆ B — INCYDENT: tłumaczenia `de/cs/sk/fr/es` nadpisane polskim

## Objaw
Na liście/matrycy produkty miały we WSZYSTKICH językach polską nazwę, np.:
```
07.043:  pl/de/fr/es = "Stalowa Osłona pod silnik Alfa Romeo Mito"
```
zamiast `de="Stahl Unterfahrschutz für Motor…"`, `fr="Acier plaque couvercle…"`, `es="Cubre carter del motor…"`.

## Jak działa system tłumaczeń (klucz do zrozumienia)
- Obce nazwy **nie są wpisywane ręcznie** — generuje je **matryca fraz → composer** (`ProductTranslationComposer`): `TranslationPhrase` + `renditions` (np. de="Stahl Unterfahrschutz für Motor") sklejane z marką+modelem z atrybutów.
- **Źródło prawdy = frazy w matrycy** (`translation_phrases` ~34, `renditions` ~374), NIE `products.name`.
- `products.name` (JSON Spatie) = wyrenderowany WYNIK — nadpisywalny.
- `translation_overrides` = **locki** (per `translatable`,`field`,`locale`,`source`). `LOCKING_SOURCES = [manual, sheet_import, auto_matrix]`. Composer ORAZ `SumpguardSource` **pomijają/zachowują** sloty z tymi lockami.

## Root cause — co się zjebało
**Mechanizm (pewny — potwierdzony kodem i README matrycy):** `SumpguardSource` **z definicji wpisuje nazwę PL do WSZYSTKICH lokali naraz** (źródło nie ma realnych tłumaczeń → podstawia polski do każdego slotu `de/cs/sk/fr/es`). Jedyną ochroną przed tym są **locki** w `translation_overrides` — sync usuwa zablokowane locale z payloadu i przywraca istniejące wartości (`app/Sources/SumpguardSource.php:341-358`). Dodatkowo leci **nocny cron ~01:00** uruchamiający ten sync.

Czyli korupcja = **sync Sumpguard przebiegł, gdy na `de/fr/es` NIE było locków** → wpisał polski fallback do wszystkich lokali.

**Najbardziej spójny scenariusz:** w trakcie prac nad tłumaczeniami **usunięto locki `name`** (typowy krok „przebudowy": *najpierw usuń wpis w `translation_overrides`*) i **nie odpalono od razu** `auto-translate` — a w międzyczasie (cron 01:00 albo ręczny `sumpguard:update`) sync wpisał PL do wszystkich lokali. Locki później odtworzone → `auto-translate` je pomijał i nie naprawiał, więc `de/fr/es` zostały na PL.

> Wniosek na przyszłość: **nigdy nie zostawiaj skasowanych locków `name` „na potem"** — usuń locki → **natychmiast** `auto-translate` → koniec. Nigdy nie zostawiaj tego stanu przez noc (cron 01:00 nadpisze PL).

## Czego to NIE była wina
- **Nie** zmiany w gridzie/kontrolerze z Części A — `IntegrationProductController@index` tylko **czyta** (`addAllEnabledProducts` rusza wyłącznie `integration_products`, nie `products`/tłumaczenia).
- **Nie** komend diagnostycznych — wszystkie były read-only (`count`/`get`/`compose`).
- `products.name` zapisują tylko: `SumpguardSource`, `translations:import-from-sheet`, `composer->apply`. Żaden z nich nie był uruchamiany przez prace nad gridem.

## Diagnostyka (read-only — jak to ustaliliśmy)
Wszystkie skrypty bootują Laravel ręcznie (uwaga: **wymagany `require __DIR__.'/vendor/autoload.php';` PRZED `bootstrap/app.php`**):
```php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
```
- **Czy dane są (klucze locali):** `Product::where('name','like','%"de":%')->count()` per locale + `TranslationOverride::count()` (było 16254 — baza nie po wipe).
- **Czy obce są realne, nie kopia PL:** dla kilku znanych kodów (`07.043`,`01.101`,`30.149`) `getTranslations('name')` → pokazało `de=fr=es=PL` = potwierdzenie nadpisania.
- **Czy matryca żyje (decyduje o odbudowie):** `app(ProductTranslationComposer::class)->compose($p)` (NIC nie zapisuje) → zwracało poprawne `de:Stahl… / fr:Acier… / es:Cubre carter…` = matryca cała → odbudowa możliwa.

## RECEPTA ODBUDOWY (sprawdzona 2026-06-26 — odtworzyła 1604/1606 produktów)
Wszystko na prodzie, `cd ~/domains/pim.bsplate.eu/PIM`, przez `/usr/local/php83/bin/php`.

**1. Backup w bazie (siatka bezpieczeństwa):**
```bash
php artisan tinker --execute="DB::statement('CREATE TABLE products_bkp_YYYYMMDD AS SELECT * FROM products'); DB::statement('CREATE TABLE tro_bkp_YYYYMMDD AS SELECT * FROM translation_overrides'); echo 'BACKUP OK';"
```

**2. Dry-run (czyta matrycę, nic nie pisze — `matched` ≈ cała baza?):**
```bash
php artisan translations:auto-translate --dry-run
```

**3. Usuń locki nadpisanych slotów (de/cs/sk/fr/es), ZACHOWAJ `manual`** (skrypt `_fix1.php` z bootstrapem jak wyżej):
```php
$type = (new App\Models\Product)->getMorphClass();
$q = App\Models\TranslationOverride::query()
    ->where('translatable_type',$type)->where('field','name')
    ->whereIn('locale',['de','cs','sk','fr','es'])
    ->whereIn('source',['auto_matrix','sheet_import']);   // manual NIE ruszane
echo "usunieto: ".$q->delete()."\n";
```
> Usuwa ~7–8 tys. locków (5 lokali × ~1490) — to normalne, zakładane na nowo w kroku 4.

**4. Odbuduj nazwy z matrycy (ZAPISUJE, zakłada lock `auto_matrix`):**
```bash
php artisan translations:auto-translate
```
Wynik tym razem: `matched 1604`, `applied_locales 8020` (=1604×5), `skipped_locked 6689` (to nadpisania Allegro `int:{id}`, których nie ruszaliśmy — OK), `unmatched 2`.

**5. Weryfikacja:** `getTranslations('name')` dla `07.043` → `de:Stahl… fr:Acier… es:Cubre carter…`. W przeglądarce `Ctrl+Shift+R`.

> Rollback gdyby coś poszło źle: dane sprzed odbudowy są w `products_bkp_YYYYMMDD` / `tro_bkp_YYYYMMDD`.

## Prewencja — żeby nie wróciło
- Po odbudowie `de/cs/sk/fr/es` mają lock **`auto_matrix`** → `SumpguardSource` i composer **je pomijają**. Normalny sync źródła **NIE** nadpisze już tych slotów. Odbudowa jest trwała.
- **Zasada:** usunięcie locków `name` + sync/import w międzyczasie = ryzyko nadpisania PL. Robić tylko: usuń locki → **od razu** `auto-translate` → koniec.
- Przed jakąkolwiek operacją masową na tłumaczeniach: **`CREATE TABLE ..._bkp AS SELECT * FROM products`** najpierw.
- Zostały **2 produkty `unmatched`** (brak frazy w matrycy) — mają wciąż PL w obcych; do ręcznego dopisania frazy w `/admin/translation-phrases` → „Reaplikuj".

## Wynik
Tłumaczenia odbudowane i potwierdzone wizualnie. Matryca, locki (16254) i frazy (34/374) były cały czas nietknięte — nadpisany był tylko wyrenderowany `products.name`, który matryca odtworzyła jedną komendą.

Patrz też: `docs/matryca-tlumaczen/` (01-architektura, 02-komendy, 04-sklejacz).
