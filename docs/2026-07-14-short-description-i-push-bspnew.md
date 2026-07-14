# 2026-07-14 — Short description do payloadu + pełny push na bspnew.test

## TL;DR

- **Problem:** PIM renderował krótki opis z szablonu, ale connector wysyłał do sklepu twarde `'short_description' => null` → sklepy nie dostawały krótkich opisów.
- **Fix:** `seo.short_description` = mapa i18n z `info_2` + nakładka z szablonu (analogicznie do `description`/`info_1`). 3 czyste commity, wypushowane na `main`.
- **Test:** pełny push katalogu na lokalny `bspnew.test` (OpenCart) — 1491/1491 produktów, 0 błędów, potwierdzone w bazie sklepu.
- **Do zrobienia:** deploy na prod `bsplate.de` (PrestaShop — tam short_description **będzie** widoczny). Paczka: `short_description_fix_2026-07-14.zip`.

---

## 1. Problem

Szablon PIM (`Templates`, np. `oslonypareto.de`, locale `de`) ma pole **Short Description** z HTML-em (`Stahlstärke: {{ $width }}mm`, `Schützt: {{ $attribute_protection }}`). PIM go renderuje (`Template::getRenderedShortDescription`), ale w payloadzie do sklepu leciało twarde `null`.

Miejsca budowania payloadu (metoda `buildImportItem`):
- `app/Services/Integration/Pipelines/CatalogCreatePipeline.php` — **aktywna ścieżka**
- `app/Services/Integration/Pipelines/CatalogDeltaPipeline.php` — **aktywna ścieżka**
- `app/Services/Integration/SyncService.php` — legacy

We wszystkich trzech `'seo' => ['short_description' => null, ...]`. Dodatkowo pipeline'y nawet **nie renderowały** short (brak wywołania `getRenderedShortDescription`).

## 2. Diagnoza — jak to jedzie end-to-end

- **Pole źródłowe:** `info_1` → `seo.description` (długi opis), `info_2` → `seo.short_description` (krótki). Konwencja `info_2=short` potwierdzona w `app/Sources/GroomershopSource.php` i `GabySource.php`.
- **Format:** każde pole SEO to **mapa i18n** `[locale => html]`; pusty slot = `new \stdClass()` (`{}`), **nie** `null` (connector Presta robi `isset()` — `null` pomija, `{}` czyści).
- **Hasher:** `PayloadHasher::normalizeSeo` już liczy `short_description` w hashu → po wypełnieniu **delta sam wykrywa zmianę i przepushuje** istniejące produkty. Osobny „backfill" niepotrzebny.
- **Strona odbiorcza:**
  - **PrestaShop** (`storage/app/pim-connector-presta.php`): `seo.short_description` → `ps_product_lang.description_short` — **zapisuje** ✅
  - **OpenCart** (`pim-connector-opencart.php`): `oc_product_description` nie ma kolumny short desc → connector czyta tylko `seo.description`, **short_description ignoruje**.

## 3. Fix — 3 commity (single-concern)

| Commit | Zakres | Pliki |
|---|---|---|
| `badeeb9` | **short_description → payload** (było `null`) | SyncService + CatalogCreate + CatalogDelta |
| `21028f9` | roczniki w nazwie (yearSuffix — wisiało niezacommitowane) | CatalogCreate + CatalogDelta |
| `a897260` | media: `pim_id` dla connectora OpenCart | MediaSyncPipeline |

Wzorzec fixa short_description (lustro obsługi długiego opisu):

```php
// render z szablonu
try { $renderedShort = trim((string) $template->getRenderedShortDescription($product)); } catch (\Throwable) {}

// baza z tłumaczeń info_2 + nakładka z szablonu
$info2 = array_filter($product->getTranslations('info_2'), fn ($v) => trim(strip_tags((string) $v)) !== '');
if ($renderedShort !== '') $info2[$locale] = $renderedShort;

// w payloadzie
'seo' => [
    'short_description' => $info2 ?: new \stdClass(),   // było: null
    ...
]
```

**Uwaga o rozdzieleniu commitów:** w `CatalogDeltaPipeline` deklaracja `$renderedShort` (A) i blok `yearSuffix` (B) były w jednym hunku (sąsiedni kod), więc czysty rozdział wymagał cięcia pod-hunkowego (rekonstrukcja z backupem, każdy krok zweryfikowany `php -l`).

## 4. Test — pełny push na bspnew.test (lokalny OpenCart)

Integracja **id=25 „OPEN - Test"** → `http://bspnew.test` (lokalny OpenCart, `enabled=1`).

Uruchomienie (kolejka `database` → potrzebny worker; łańcuch `RunConnectorChainJob` → CatalogCreate→Delta na `sync-catalog`, media na `sync-media`, blog na `sync-blog`):

```bash
php artisan tinker --execute="App\Jobs\Connectors\RunConnectorChainJob::dispatchSync(25,'manual');"
php artisan queue:work database --queue=sync-catalog --stop-when-empty --tries=1 --timeout=3600
```

Wynik (`catalog_create` run id=18): **1491/1491**, 0 błędów, 3 min 40 s. Delta: skipy (produkty świeżo utworzone).

**Weryfikacja w bazie sklepu `bspnew`:**

| Metryka | Wartość |
|---|---|
| `oc_product` | 1491 |
| `oc_pim_product_link` | 1491 |
| `oc_product_description` | 2982 (1491 × PL/DE) |
| `oc_category` | 508 |

- **yearSuffix działa:** nazwy DE z zakresem lat — `Alfa Romeo Mito (2008-2018)`, `Alfa Romeo 156 (1997-2003)`, `Fiat Grande Punto (2006-2018)`.
- **short_description niewidoczny w OpenCart** (poprawnie) — `oc_product_description` ma tylko `name, description, tag, meta_title, meta_description, meta_keyword`, brak short desc. Do zobaczenia short_description potrzebny PrestaShop.

## 5. Deploy na produkcję (bsplate.de — PrestaShop)

Prod = PrestaShop, więc short_description **będzie** widoczny (`description_short`). Prod bez gita/composera → paczka ręczna.

**Paczka:** `_deploy_short_description_2026-07-14.zip` (separatory `/`, NIE robić Compress-Archive), zawiera 5 plików + DEPLOY.md:
- `app/Services/Integration/SyncService.php`
- `app/Services/Integration/Pipelines/CatalogCreatePipeline.php`
- `app/Services/Integration/Pipelines/CatalogDeltaPipeline.php`
- `app/Services/Integration/Pipelines/MediaSyncPipeline.php`
- `app/Models/IntegrationProduct.php` — fix null-override (override `name:null` NIE zeruje nazwy; incydent 2026-07-02, commit `c0793b7`, wg sesji „selly 2/3" czekał na prod)

**Kroki na prod:**
1. Backup 4 plików na prod.
2. Rozpakuj zip do rootu PIM (nadpisze 4 pliki).
3. `php artisan optimize:clear` (odsłania ewentualne parse errory — cache tras maskuje).
4. Odpal delta sync integracji bsplate.de → hash każdego produktu się zmieni (doszedł short_description) → delta przepushuje short descriptions do `ps_product_lang.description_short`.

**Uwaga:** paczka niesie też yearSuffix (roczniki w nazwach) i media pim_id — świadome, zacommitowane zmiany. Dla produktu bez template-short i bez `info_2` payload wyśle puste `{}` → delta nadpisze `description_short` pustką (tak samo jak już działa `description`). Dla bsplate.de szablon renderuje short każdemu, więc to praktycznie nie wystąpi.

## 6. Stan repo

- Gałąź `main`, wypushowane: `20cb4d9..a897260`
- Weryfikacja: `git show HEAD:app/Services/Integration/SyncService.php | grep short_description` → linia w `seo` pokazuje `$info2`, nie `null`.
