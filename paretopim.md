# ParetoPIM — Dokumentacja Techniczna

> Dokument przygotowany dla Opus na potrzeby stworzenia paczki aktualizacyjnej.
> Stan: 2026-04-30 | Środowisko: Laragon (Windows), `http://pim.test`

---

## 1. Stack technologiczny

| Warstwa | Technologia |
|---|---|
| Backend | Laravel 10.x |
| PHP | >= 8.1 (wymagane, testowane na 8.2+) |
| Frontend | Vue 3 + Inertia.js 0.6 + Vite |
| CSS | Tailwind CSS |
| Baza danych | MySQL |
| Kolejka | Database queue (`jobs` table) |
| Media | Spatie MediaLibrary 10 |
| Uprawnienia | Spatie Laravel Permission 5.5 |
| Ustawienia | Spatie Laravel Settings 2.6 |
| Tłumaczenia | Spatie Laravel Translation Loader 2.7 |
| Tagi | Spatie Laravel Tags 4.3 |
| Query Builder | Spatie Laravel Query Builder 5.0 |
| AI | OpenAI PHP Laravel 0.11 (GPT-4o) |
| Arkusze | Revolution Laravel Google Sheets 6.4 |
| Drzewa kategorii | Kalnoy NestedSet 6.0 |
| Eksport/Import | Maatwebsite Excel 3.1 |
| Lokalny package | `packages/laravel-prestashop` (dev-main, path repository) |
| HTTP klient | Guzzle 7.2 |
| Auth | Sanctum 3.2 + sesje webowe (crafter middleware) |

---

## 2. Struktura projektu

```
PIM/
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Admin/          # Panel administracyjny (Inertia/Crafter)
│   │   │   │   ├── Auth/       # Logowanie, reset hasła, weryfikacja
│   │   │   │   ├── AdminUser/  # Zarządzanie użytkownikami
│   │   │   │   ├── Media/      # Zarządzanie mediami
│   │   │   │   ├── Roles/      # Role i uprawnienia
│   │   │   │   ├── Settings/   # Ustawienia aplikacji
│   │   │   │   ├── Permissions/
│   │   │   │   └── Translations/
│   │   │   └── Api/            # Publiczne API (Baselinker, Selly)
│   │   ├── Middleware/
│   │   └── Requests/           # Form Requests per resource
│   ├── Jobs/                   # Kolejkowane zadania synchronizacji
│   ├── Models/                 # Modele Eloquent
│   ├── Services/               # Serwisy biznesowe
│   ├── Settings/               # Klasy ustawień (Spatie Settings)
│   ├── Sources/                # Klasy synchronizacji źródeł produktów
│   ├── Exports/                # Excel/CSV eksporty (Maatwebsite)
│   ├── Media/                  # Traity do obsługi mediów
│   └── Queries/Filters/        # FuzzyFilter dla QueryBuilder
├── resources/
│   └── js/
│       └── crafter/
│           ├── Components/     # Reużywalne komponenty Vue
│           ├── Layouts/        # Authenticated.vue, Guest.vue
│           └── Pages/          # Strony Inertia (po jednej na zasób)
├── routes/
│   ├── web.php                 # Redirect / → /admin
│   ├── crafter.php             # Wszystkie trasy panelu admin
│   ├── api.php                 # API Baselinker + Selly
│   ├── console.php
│   └── channels.php
├── packages/
│   └── laravel-prestashop/     # Lokalny package do integracji PS
└── database/
    ├── migrations/
    └── seeders/
```

---

## 3. Baza danych — schemat tabel

### `products`
| Kolumna | Typ | Uwagi |
|---|---|---|
| id | bigint PK | |
| source_id | bigint FK | → sources.id |
| external_id | bigint UNIQUE | ID z systemu zewnętrznego |
| ean | string | nullable |
| category | string | legacy (stare pole tekstowe) |
| name | json | translatable (pl, en, …) |
| product_code | string | SKU |
| width | decimal | nullable |
| weight | decimal | nullable |
| comment | text | nullable |
| enabled | boolean | default: false |
| info_1 | json | translatable — długi opis |
| info_2 | json | translatable — krótki opis |
| info_3 | json | translatable — dodatkowe info |
| meta_url | json | translatable |
| meta_title | json | translatable |
| meta_description | json | translatable |
| meta_keywords | json | translatable |
| timestamps | | |

**Relacje:**
- `belongsTo` Source
- `belongsToMany` AttributeValue (pivot: `attribute_value_product`)
- `belongsToMany` Pricelist (pivot: `pricelist_product` z kolumną `price`)
- `belongsToMany` Category (pivot: `category_product`)
- Media (Spatie MediaLibrary, kolekcja `images`, mimetypes: jpeg/jpg/png/gif, max 2MB)

---

### `categories`
| Kolumna | Typ | Uwagi |
|---|---|---|
| id | bigint PK | |
| _lft | int | NestedSet |
| _rgt | int | NestedSet |
| parent_id | bigint nullable | |
| name | json | translatable |
| external_id | string nullable | ID z systemu zewnętrznego (dodane 2025-11) |
| timestamps | | |

**Relacje:**
- NodeTrait (NestedSet) — drzewo hierarchiczne
- `belongsToMany` Product (pivot: `category_product`)

---

### `attributes`
| Kolumna | Typ | Uwagi |
|---|---|---|
| id | bigint PK | |
| name | json | translatable |
| slug | string UNIQUE | auto-generowany z name |
| order | int | kolejność wyświetlania |
| timestamps | | |

**Relacje:**
- `hasMany` AttributeValue

---

### `attribute_values`
| Kolumna | Typ | Uwagi |
|---|---|---|
| id | bigint PK | |
| attribute_id | bigint FK | → attributes.id |
| name | json | translatable |
| slug | string | auto-unikalne per attribute_id |
| timestamps | | |

**Pivot:** `attribute_value_product` (attribute_value_id, product_id)

---

### `sources`
| Kolumna | Typ | Uwagi |
|---|---|---|
| id | bigint PK | |
| name | string | |
| service_class | string | np. `VirsalSource` → `App\Sources\VirsalSource` |
| options | json | konfiguracja źródła |
| enabled | boolean | |
| timestamps | | |

---

### `integrations`
| Kolumna | Typ | Uwagi |
|---|---|---|
| id | bigint PK | |
| category_id | bigint FK nullable | → categories.id |
| type | string | `baselinker` \| `prestashop` \| `selly` |
| manufacturer | string nullable | nazwa producenta |
| name | string | |
| key | string nullable | MD5 klucz API (auto-generowany) |
| url | string nullable | URL endpointu API |
| sheet_id | string nullable | ID arkusza Google Sheets |
| enabled | boolean | |
| timestamps | | |

**Relacje:**
- `hasMany` IntegrationSource
- `belongsTo` Category

---

### `integration_sources`
| Kolumna | Typ | Uwagi |
|---|---|---|
| id | bigint PK | |
| integration_id | bigint FK | → integrations.id CASCADE |
| source_id | bigint FK | → sources.id CASCADE |
| template_id | bigint FK | → templates.id CASCADE |
| pricelist_id | bigint FK | → pricelists.id CASCADE |
| tax | smallint | domyślnie 23 |
| multiplier | decimal(5,2) | domyślnie 1.00 |
| timestamps | | |

---

### `integration_products`
| Kolumna | Typ | Uwagi |
|---|---|---|
| id | bigint PK | |
| integration_id | bigint FK | → integrations.id |
| integration_source_id | bigint FK nullable | → integration_sources.id CASCADE |
| product_id | bigint FK | → products.id |
| external_id | string nullable | ID w systemie docelowym |
| overrides | json nullable | nadpisane pola produktu per integracja |
| synced_at | timestamp nullable | czas ostatniej synchronizacji |
| timestamps | | |

---

### `pricelists`
| Kolumna | Typ | Uwagi |
|---|---|---|
| id | bigint PK | |
| slug | string | |
| name | string | |
| currency | string | |
| sheet_id | string nullable | ID arkusza Google Sheets |
| timestamps | | |

**Pivot:** `pricelist_product` (pricelist_id, product_id, price decimal)

---

### `templates`
| Kolumna | Typ | Uwagi |
|---|---|---|
| id | bigint PK | |
| slug | string | |
| locale | string | np. `pl`, `en` |
| name | string | |
| title | text | Blade template z `{{ $name }}`, `{{ $attribute_slug }}` itp. |
| short_description | text | Blade template |
| description | text | Blade template |
| meta_title | text | Blade template |
| meta_description | text | Blade template |
| timestamps | | |

**Ważne:** Pola `title`, `description` itd. są renderowane przez `Blade::render()` z danymi produktu. Dostępne zmienne to wszystkie pola produktu + `attribute_{slug}` dla każdego atrybutu.

---

### `ai_tools`
| Kolumna | Typ | Uwagi |
|---|---|---|
| id | bigint PK | |
| name | json | translatable |
| description | json | translatable |
| provider | string | np. `openai` |
| config | json | konfiguracja narzędzia (model, system_content, user_content, temperature, max_tokens, top_p, frequency_penalty, presence_penalty) |
| enabled | boolean | |
| order | int | |
| timestamps | | |

---

### `admin_users`
Tabela użytkowników panelu. Obsługa przez Crafter (package wewnętrzny). Wspiera role/uprawnienia Spatie Permission.

### Tabele Spatie Permission
`permissions`, `roles`, `model_has_permissions`, `model_has_roles`, `role_has_permissions`

### Tabele pomocnicze
- `jobs` — kolejka database
- `failed_jobs`
- `personal_access_tokens` — Sanctum
- `media` — Spatie MediaLibrary
- `settings` — Spatie Settings
- `language_lines` — Spatie Translation Loader (tłumaczenia z DB)
- `tags`, `taggables` — Spatie Tags
- `unassigned_media` — media bez przypisanego produktu
- `admin_password_resets`, `password_reset_tokens`

---

## 4. Endpointy — Panel Admin (`/admin`, prefix: `crafter.`)

Middleware stack: `crafter.base` → `auth` → `crafter.verified`

### Auth (gość)
| Metoda | URL | Akcja |
|---|---|---|
| GET | /admin/login | Formularz logowania |
| POST | /admin/login | Logowanie |
| GET | /admin/forgot-password | Reset hasła |
| POST | /admin/forgot-password | Wyślij link reset |
| GET | /admin/reset-password/{token} | Nowe hasło |
| POST | /admin/reset-password | Zapisz nowe hasło |
| GET | /admin/invite-user/{email} | Akceptacja zaproszenia |
| POST | /admin/invite-user | Rejestracja z zaproszenia |

### Ogólne (zalogowany)
| Metoda | URL | Akcja |
|---|---|---|
| GET | /admin/ | Home (redirect do default_route) |
| GET | /admin/dashboard | Dashboard |
| POST | /admin/logout | Wylogowanie |
| POST | /admin/upload | Upload pliku |
| POST | /admin/media-zip | Pobierz ZIP mediów |
| POST | /admin/unassigned-media-upload | Upload niezapisanych mediów |
| DELETE | /admin/unassigned-media-destroy/{id} | Usuń niezapisane medium |

### Produkty (`products.*`)
| Metoda | URL | Akcja |
|---|---|---|
| GET | /admin/products | Lista |
| GET | /admin/products/create | Formularz dodania |
| POST | /admin/products | Zapisz nowy |
| GET | /admin/products/export-import | Widok eksport/import |
| GET | /admin/products/export | Eksportuj (Excel) |
| POST | /admin/products/import | Importuj (Excel) |
| GET | /admin/products/edit/{product}/ai | Edycja AI |
| GET | /admin/products/edit/{product} | Edycja |
| PUT/PATCH | /admin/products/{product} | Aktualizuj |
| DELETE | /admin/products/{product} | Usuń |
| POST | /admin/products/bulk-destroy | Masowe usunięcie |

### Kategorie (`categories.*`)
| Metoda | URL | Akcja |
|---|---|---|
| GET | /admin/categories | Lista (drzewo NestedSet) |
| GET | /admin/categories/create | Formularz |
| POST | /admin/categories | Zapisz |
| GET | /admin/categories/edit/{category} | Edycja |
| PUT/PATCH | /admin/categories/{category} | Aktualizuj |
| DELETE | /admin/categories/{category} | Usuń |
| POST | /admin/categories/bulk-destroy | Masowe usunięcie |

### Atrybuty (`attributes.*`)
| Metoda | URL | Akcja |
|---|---|---|
| GET | /admin/attributes | Lista |
| GET | /admin/attributes/create | Formularz |
| POST | /admin/attributes | Zapisz |
| POST | /admin/attributes/update-order | Zmień kolejność (drag & drop) |
| GET | /admin/attributes/edit/{attribute} | Edycja |
| PUT/PATCH | /admin/attributes/{attribute} | Aktualizuj |
| DELETE | /admin/attributes/{attribute} | Usuń |
| POST | /admin/attributes/bulk-destroy | Masowe usunięcie |

### Wartości atrybutów (`attribute-values.*`)
| Metoda | URL | Akcja |
|---|---|---|
| GET | /admin/attribute-values | Lista |
| GET | /admin/attribute-values/create | Formularz |
| POST | /admin/attribute-values | Zapisz |
| GET | /admin/attribute-values/edit/{attributeValue} | Edycja |
| PUT/PATCH | /admin/attribute-values/{attributeValue} | Aktualizuj |
| DELETE | /admin/attribute-values/{attributeValue} | Usuń |
| POST | /admin/attribute-values/bulk-destroy | Masowe usunięcie |

### Źródła (`sources.*`)
| Metoda | URL | Akcja |
|---|---|---|
| GET | /admin/sources | Lista |
| GET | /admin/sources/create | Formularz |
| POST | /admin/sources | Zapisz |
| GET | /admin/sources/edit/{source} | Edycja |
| PUT/PATCH | /admin/sources/{source} | Aktualizuj |
| DELETE | /admin/sources/{source} | Usuń |
| POST | /admin/sources/bulk-destroy | Masowe usunięcie |

### Cenniki (`pricelists.*`)
| Metoda | URL | Akcja |
|---|---|---|
| GET | /admin/pricelists | Lista |
| GET | /admin/pricelists/create | Formularz |
| POST | /admin/pricelists | Zapisz |
| GET | /admin/pricelists/edit/{pricelist} | Edycja |
| GET | /admin/pricelists/sync/{pricelist} | Synchronizuj z Google Sheets |
| PUT/PATCH | /admin/pricelists/{pricelist} | Aktualizuj |
| DELETE | /admin/pricelists/{pricelist} | Usuń |
| POST | /admin/pricelists/bulk-destroy | Masowe usunięcie |

### Szablony (`templates.*`)
| Metoda | URL | Akcja |
|---|---|---|
| GET | /admin/templates | Lista |
| GET | /admin/templates/create | Formularz |
| POST | /admin/templates | Zapisz |
| GET | /admin/templates/edit/{template} | Edycja |
| GET | /admin/templates/preview/{template} | Podgląd renderowania |
| PUT/PATCH | /admin/templates/{template} | Aktualizuj |
| DELETE | /admin/templates/{template} | Usuń |
| POST | /admin/templates/bulk-destroy | Masowe usunięcie |

### Integracje (`integrations.*`)
| Metoda | URL | Akcja |
|---|---|---|
| GET | /admin/integrations | Lista |
| GET | /admin/integrations/create | Formularz |
| POST | /admin/integrations | Zapisz + generateApiData() + createIntegration() w GSheets |
| GET | /admin/integrations/edit/{integration} | Edycja |
| PUT/PATCH | /admin/integrations/{integration} | Aktualizuj + syncIntegrationSources |
| DELETE | /admin/integrations/{integration} | Usuń |
| GET | /admin/integrations/sync/{integration} | Uruchom SynchronizeIntegration job |
| GET | /admin/integrations/sync-sheet/{integration} | Synchronizuj arkusz GSheets |
| POST | /admin/integrations/bulk-destroy | Masowe usunięcie |

### Produkty integracji (`integration-products.*`)
| Metoda | URL | Akcja |
|---|---|---|
| GET | /admin/integrations/{integration}/products | Lista |
| GET | /admin/integrations/{integration}/products/export | Eksport CSV/Excel |
| PUT/PATCH | /admin/integrations/{integration}/products | Masowe nadpisanie (overrides) |

### AI Tools (`ai-tools.*`)
| Metoda | URL | Akcja |
|---|---|---|
| GET | /admin/ai-tools | Lista |
| GET | /admin/ai-tools/create | Formularz |
| POST | /admin/ai-tools | Zapisz |
| GET | /admin/ai-tools/edit/{aiTool} | Edycja |
| PUT/PATCH | /admin/ai-tools/{aiTool} | Aktualizuj |
| DELETE | /admin/ai-tools/{aiTool} | Usuń |
| POST | /admin/ai-tools/bulk-destroy | Masowe usunięcie |
| GET | /admin/api/ai-tools | JSON lista aktywnych narzędzi AI |
| POST | /admin/api/ai-tools/execute | Wykonaj narzędzie AI na produkcie |

### Pozostałe
| Metoda | URL | Akcja |
|---|---|---|
| GET | /admin/media | Lista mediów |
| GET | /admin/media/images | Tylko obrazy |
| GET | /admin/media/files | Tylko pliki |
| POST | /admin/media/update/{media} | Aktualizuj metadane medium |
| GET | /admin/permissions | Widok uprawnień |
| PUT | /admin/permissions | Aktualizuj uprawnienia |
| GET | /admin/roles | Lista ról |
| GET | /admin/roles/{role}/edit | Edycja roli |
| PUT | /admin/roles/{role}/update | Aktualizuj rolę |
| GET | /admin/settings | Ustawienia aplikacji |
| PUT | /admin/settings | Zapisz ustawienia |
| GET | /admin/translations | Lista tłumaczeń |
| POST | /admin/translations/rescan | Rescan plików tłumaczeń |
| GET | /admin/translations/export | Eksportuj tłumaczenia |
| POST | /admin/translations/import | Importuj tłumaczenia |
| POST | /admin/translations/publish | Publikuj tłumaczenia |
| POST | /admin/tags | Utwórz tag |
| GET | /admin/profile | Mój profil |
| PUT | /admin/profile | Aktualizuj profil |
| GET | /admin/admin-users | Lista użytkowników |
| ... | /admin/admin-users/* | Pełny CRUD + invite + impersonate |

---

## 5. Endpointy — Publiczne API (`/api`)

### Baselinker Connector
```
GET/POST /api/baselinker/{integration_id}?key={md5_key}&action={action}
```
Akcja domyślna: `FileVersion`. Klucz = `md5("password_{integration_id}")`.

Obsługiwane akcje:
| Akcja | Opis |
|---|---|
| `FileVersion` | Wersja pliku (platform: "PIM", version: "0.1", standard: 4) |
| `SupportedMethods` | Lista obsługiwanych metod |
| `ConnectDatabase` | Test połączenia (zawsze true) |
| `Products` | Cała lista produktów integracji (cache 1h) |
| `ProductsList` | Filtrowana lista (category_id, filter_limit, filter_sort, filter_id, filter_ean, filter_sku, filter_name, filter_price_from/to, filter_quantity_from/to, filter_available) |
| `ProductsData` | Szczegóły wybranych produktów po IDs |
| `ProductsCategories` | Lista kategorii z produktów |

Dane produktu Baselinker:
```json
{
  "id": "external_id",
  "name": "rendered_title",
  "sku": "product_code",
  "ean": "ean",
  "description": "rendered_description",
  "quantity": 100,
  "man_name": "integration.manufacturer",
  "category_id": "md5(category_name)",
  "category_name": "Kat1/Kat2",
  "tax": 23,
  "price": "ceil(price * multiplier)",
  "images": ["url1", "url2"],
  "features": [],
  "enabled": true
}
```

### Selly Connector
```
GET/POST /api/selly/{integration_id}?key={md5_key}
```
Zwraca plik CSV/Excel z produktami integracji (generowany przez `SellyIntegrationProductsExport`).

### Connector PIM (WordPress plugin update)
```
GET /update-connector
```
Zwraca plik `storage/app/pim-connector.php` — plik łącznika dla zewnętrznych systemów.

---

## 6. Serwisy

### `ChatGptService`
- `generate(array $settings, array $response_format)` — surowe wywołanie OpenAI Chat
- `generateProductContent(array $settings, array $product, string $locale)` — generuje pola produktu (name, info_1, info_2, info_3, meta_*) używając GPT-4o z JSON Schema (structured output)

### `PrestashopService`
- `syncCategories()` — synchronizuje kategorie do PrestaShop
- `syncProducts()` — synchronizuje produkty do PrestaShop

### `GoogleSheetsService`
- `createIntegration(Integration $integration)` — tworzy arkusz dla integracji
- `syncIntegration(Integration $integration)` — synchronizuje dane z arkuszem
- Używa `PRICELISTS_SPREADSHEET_ID` i `INTEGRATIONS_SPREADSHEET_ID` z `.env`

### `BaselinkerService`
- Obsługa HTTP do API Baselinker (po stronie wychodzącego)

### `ConnectorService`
- Obsługa pliku łącznika `pim-connector.php`

### `BackupService`, `StahlService`, `SumpguardService`
- Serwisy importu specyficzne dla klientów

---

## 7. Kolejka (Jobs)

| Job | Opis |
|---|---|
| `SynchronizeIntegration` | Główny job sync. Obsługuje PrestaShop (syncCategories + syncProducts), Baselinker (update synced_at + clear cache), Selly (generuj CSV). Timeout: 7200s, tries: 1 |
| `SynchronizeSource` | Synchronizacja źródła produktów |
| `ImportVirsalProducts` | Import produktów z Virsal |
| `ImportArgoProducts` | Import produktów z Argo |
| `ImportDogdesignProduxts` | Import produktów z Dogdesign |
| `ImportOslonyparetoPricelist` | Import cennika Oslonypareto |
| `ImportProductsEans` | Import EAN-ów produktów |
| `ImportNewMedia` | Import nowych mediów |

Uruchamianie kolejki: `php artisan queue:work --timeout=7200`

---

## 8. Ustawienia aplikacji

Klasa `App\Settings\GeneralSettings` (Spatie Settings, group: `general`):

| Pole | Typ | Opis |
|---|---|---|
| `available_locales` | array | Lista języków, np. `['pl', 'en']` |
| `default_locale` | string | Domyślny język |
| `default_route` | string | Trasa po zalogowaniu |

---

## 9. System uprawnień

Używa Spatie Laravel Permission. Uprawnienia definiowane są przez migracje i dostępne w UI przez `/admin/permissions` (macierz rola-uprawnienie) i `/admin/roles`.

Grupy uprawnień (z migracji):
- `products` — viewAny, view, create, update, delete, publish
- `pricelists` — viewAny, view, create, update, delete
- `templates` — viewAny, view, create, update, delete
- `integrations` — viewAny, view, create, update, delete
- `integration_products` — viewAny, view, create, update, delete
- `sources` — viewAny, view, create, update, delete
- `attributes` — viewAny, view, create, update, delete
- `attribute_values` — viewAny, view, create, update, delete
- `categories` — viewAny, view, create, update, delete
- `ai_tools` — viewAny, view, create, update, delete

---

## 10. Lokalny package: `laravel-prestashop`

Ścieżka: `packages/laravel-prestashop/`
Namespace: `Mdev\LaravelPrestashop`

Dostępne zasoby API PrestaShop (klasy w `src/Client/Api/Request/`):
- `Product` — produkty
- `Category` — kategorie
- `Image` — obrazy produktów
- `Feature` / `FeatureValue` — cechy (odpowiednik atrybutów PIM)
- `Tax` / `TaxRule` — podatki
- `Manufacturer` — producenci
- `Language` — języki
- `Shop` — sklepy
- `StockAvailable` — stany magazynowe
- `Permission` — sprawdzanie uprawnień klucza API

Facade: `Prestashop::` (przez `LaravelPrestashopServiceProvider`)

---

## 11. Frontend — Vue 3 + Inertia

Strony (Pages) — jedna na zasób:
- `AiTool/Index`, `Create`, `Edit`, `Form`
- `Attribute/Index`, `Create`, `Edit`, `Form`
- `AttributeValue/Index`, `Create`, `Edit`, `Form`
- `Category/Index`, `Create`, `Edit`, `Form`
- `Integration/Index`, `Create`, `Edit`, `Form`
- `Source/Index`, `Create`, `Edit`, `Form`
- `Pricelist/Index`, `Create`, `Edit`, `Form`
- `Template/Index`, `Create`, `Edit`, `Form`
- `Product/Index`, `Create`, `Edit`, `Form`
- `Media/Index`
- `Roles/Index`, `Edit`, `Form`, `Permission`

Kluczowe komponenty (`crafter/Components/`):
- `Wysiwyg` — edytor WYSIWYG (TipTap-based)
- `Listing` — tabela z paginacją, sortowaniem, filtrami, bulk select
- `Dropzone` — upload plików/mediów
- `Multiselect`, `SelectInput`, `TreeSelect` — selecty
- `Card`, `CardLocaleSwitcher` — karty z przełączaniem języka
- `Modal`, `ImageUploadModal` — modale
- `TabGroup`, `Tab` — zakładki
- `Toggle`, `Checkbox`, `RadioGroup` — pola formularzy

---

## 12. Konfiguracja środowiska (.env)

```env
APP_NAME=PIM
APP_URL=http://pim.test
APP_INSTANCE=pareto
APP_KEY=base64:...   # już ustawiony

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=pareto
DB_USERNAME=root
DB_PASSWORD=

QUEUE_CONNECTION=database
CACHE_DRIVER=file
SESSION_DRIVER=file

MAIL_MAILER=smtp
MAIL_HOST=live.smtp.mailtrap.io
MAIL_PORT=587

# Google Sheets — wymagane do integracji
PRICELISTS_SPREADSHEET_ID=181iMipLVeNDpWJSP-frcO3jM9mnGpE5MHgp_w6n_tLs
INTEGRATIONS_SPREADSHEET_ID=1BpQNP2-OWDH6um8YLN7UsIFa2hcLOSiGBHJe6OuwgVE

# OpenAI — wymagane do AI Tools
OPENAI_API_KEY=sk-...   # uzupełnić
```

---

## 13. Uruchomienie lokalne (po klonowaniu)

```bash
# 1. Wymagania: PHP >= 8.2, Composer, Node.js, MySQL, Laragon

# 2. Zależności PHP
composer install

# 3. Plik środowiska
cp .env.example .env
php artisan key:generate

# 4. Baza danych (utwórz bazę `pareto` w MySQL, następnie)
php artisan migrate

# 5. Symlink storage
php artisan storage:link

# 6. Frontend (jeśli potrzebujesz przebudować assets)
yarn install
yarn build

# 7. Kolejka (w osobnym terminalu)
php artisan queue:work --timeout=7200
```

---

## 14. Kluczowe mechanizmy — ważne dla developmentu

### Tłumaczenia produktów
Pola `name`, `info_1`, `info_2`, `info_3`, `meta_*` są przechowywane jako JSON:
```json
{"pl": "Nazwa PL", "en": "Name EN"}
```
Używa `Spatie\Translatable\HasTranslations`. Przy odczycie: `$product->getTranslation('name', 'pl')`.

### Renderowanie szablonów
`Template::getRenderedDescription($product)` wywołuje `Blade::render($this->description, $product->getVariables($locale))`.
Dostępne zmienne w szablonie:
- `{{ $name }}`, `{{ $product_code }}`, `{{ $ean }}`, `{{ $info_1 }}` itp.
- `{{ $attribute_kolor }}`, `{{ $attribute_material }}` — dynamiczne z slugów atrybutów

### IntegrationProduct::overrides
JSON z nadpisanymi polami produktu per integracja. `getOverridedProduct()` aplikuje je do modelu przed eksportem.

### Baselinker cache
Dane produktów Baselinker są cache'owane 1h (`baselinker_products_{integration_id}`). Sync job czyści cache przez `Cache::forget(...)`.

### Generowanie klucza API integracji
`Integration::generateApiData()`: `key = md5("password_{id}")`, `url = /api/{type}/{id}?key={key}`. Wywoływane przy tworzeniu integracji typu `baselinker` lub `selly`.

### NestedSet (kategorie)
`Category` używa `Kalnoy\Nestedset\NodeTrait`. `Category::toTreeSelect()` zwraca drzewo dla komponentu selecta we frontendzie.

### Source synchronizacja
`Source::synchronize()` instancjuje `App\Sources\{service_class}` i wywołuje `->synchronize()`. Klasy źródeł są w `app/Sources/`.

---

## 15. Znane sprawy / TODO z kodu

- Trasy `integration-products` create/store/edit/destroy są wykomentowane — CRUD produktów integracji obsługiwany tylko przez masowe update
- `syncSheet` integracji zwraca JSON zamiast redirect (endpoint AJAX)
- `dump()` w `Source::synchronize()` — pozostawione debug
- Klasa `PrestashopServiceCopy.php` — kopia robocza, nieużywana
- Job `SynchronizeIntegration` ma hardcoded default `integration_id = 23`
- `ImportDogdesignProduxts` — literówka w nazwie klasy (Produxts)
