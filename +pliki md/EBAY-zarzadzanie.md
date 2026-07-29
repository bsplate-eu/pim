# eBay — Zarządzanie (moduł Marketplace / własne oferty)

> Moduł do zarządzania **WŁASNYMI aukcjami eBay** (nie mylić z monitoringiem konkurencji w Argo Scope → Scrapy).
> Zbudowany i wdrożony na prod `pim.bsplate.eu` — **2026-07-02**, działa LIVE.

---

## 1. Gdzie to jest w PIM

| Miejsce | Do czego |
|---|---|
| **Argo Connect → Integracje → Ebay** | Ustawienia + **połączenie konta (OAuth)**: App ID, Cert ID, RuName, „Połącz konto eBay", + reguły automatyczne (pola w bazie) |
| **Argo Connect → Marketplace → Ebay** | **Oferty**: lista aukcji, taby rynków, mapowanie SKU, edycja ilości, operacje masowe (cena/ilość), zakładki „Automatyczne akcje" i „Logi" |

Dwie rzeczy się nie mieszają: *Integracje → Ebay* = konfiguracja/logowanie; *Marketplace → Ebay* = dane i akcje.

---

## 2. Jak to działa pod spodem

- **OAuth user-token** (Authorization Code + refresh) — do czytania **własnych** ofert i zmian. To co innego niż Browse API (`client_credentials`), które tylko czyta oferty konkurencji.
  - refresh-token szyfrowany w `scrap_ebay_settings`; access-token w Cache (2h), odświeżany automatycznie.
- **Trading API** (XML): `GetSellerList` (pobieranie aukcji), `ReviseInventoryStatus` (zmiana ceny/ilości). Autoryzacja nagłówkiem `X-EBAY-API-IAF-TOKEN` = OAuth token.
- **Model** `App\Models\Ebay\EbayOffer` (tabela `ebay_offers`): klucz `(item_id, sku, marketplace)` — obsługuje warianty i wiele rynków.

### Pliki
```
app/Models/Ebay/EbayOffer.php
app/Models/Ebay/EbayActionLog.php        (log auto-restock: co/kiedy/przed→po, tabela ebay_action_logs)
app/Models/Scrap/EbaySettings.php        (+OAuth, +auto_restock_*)
app/Services/Ebay/EbayOAuthService.php   (consent → refresh → access)
app/Services/Ebay/EbaySellClient.php     (GetSellerList, ReviseInventoryStatus, siteToMarketplace)
app/Services/Ebay/EbayOfferService.php   (syncActiveListings, matchBySku, applyAutoRestock, applyAutoAssign, logAction)
app/Http/Controllers/Admin/Connect/IntegrationEbayController.php  (OAuth connect/callback/disconnect)
app/Http/Controllers/Admin/Connect/EbayOffersController.php       (lista, assign, fetch, ceny, ilości, auto-actions, auto-assign, logs)
app/Jobs/RunEbayOffersSync.php, RunEbayPriceUpdate.php, RunEbayQuantityUpdate.php
app/Console/Commands/SyncEbayOffers.php, EbayAutoActions.php
database/migrations/2026_07_02_130000_create_ebay_action_logs_table.php
database/migrations/2026_07_02_140000_add_auto_assign_to_scrap_ebay_settings.php
resources/js/crafter/Pages/Connect/Integrations/Ebay/{Index,Offers}.vue  (Offers = taby Oferty/Automatyczne akcje/Logi)
```

---

## 3. Funkcje

### 3.1. Połączenie konta (OAuth) — jednorazowo
1. **eBay Developer** ([developer.ebay.com](https://developer.ebay.com)) → Application Keys → Production → **User Tokens** → **Add eBay Redirect URL**:
   - *Auth accepted URL:* `https://pim.bsplate.eu/admin/connect/integrations/ebay/oauth/callback`
   - *Auth declined URL:* `https://pim.bsplate.eu/admin/connect/integrations/ebay`
   - *Privacy policy URL:* dowolny działający (np. `https://bsplate.eu`)
   - ⚠️ **Zaznacz „OAuth Enabled"** przy RuName (bez tego OAuth nie działa — Auth'n'Auth to co innego, nie używamy).
   - Zapisz → skopiuj **RuName** (`BSP_Black_Steel-BSPBlack-PIM-PR-…`).
2. **PIM → Integracje → Ebay:** wklej App ID + Cert ID (`PRD-…`), **Testuj połączenie** = ✓ OK, wklej **RuName**, Zapisz → **Połącz konto eBay** → zgoda na eBay → „✓ Połączone".

### 3.2. Pobieranie ofert
- Przycisk **„Pobierz oferty"** (lub `php artisan ebay:sync-offers`) → `GetSellerList` → `ebay_offers`.
- **Rynek per oferta** czytany z `Item.Site` (Germany→EBAY_DE, France→EBAY_FR, …) → osobne taby DE/FR/ES/…
- **Auto-mapowanie po SKU:** `ebay_offers.sku` ↔ `Product.product_code` (znormalizowane).

### 3.3. Ilość (stan) — dostępna, nie wystawiona
- Pokazywana ilość = **dostępna** = `Quantity − QuantitySold` (nie „ile kiedykolwiek wystawiono"). Dzięki temu zgadza się z tym, co widać na koncie eBay (np. wyprzedana = 0).

### 3.4. Edycja ilości
- **Inline** (jak BaseLinker): klik w kolumnę **Ilość**, wpisz, **Enter** → od razu leci na eBay (`ReviseInventoryStatus`), toast „Ilość → N szt. (eBay)".
- **Masowo:** zaznacz oferty (lub „wszystkie pasujące filtrowi") → **Operacje → Zmień ilość** → *zwiększ o / zmniejsz o / ustaw na* → **Podgląd zmian** → **Zastosuj**.

### 3.5. Zmiana cen
- **Operacje → Zmień cenę (z cennika):** wybór cennika + VAT% → **Podgląd** (stara→nowa brutto) → **Zastosuj**. Cena netto z cennika × (1+VAT) = brutto na eBay.

### 3.6. Automatyczne akcje (tab „Automatyczne akcje")
Dwie reguły, każda z własnym przełącznikiem **Włączone/Wyłączone** (domyślnie włączone) i przyciskiem **„Uruchom teraz"**:
- **Auto-restock:** gdy stan aktywnej aukcji = **0** → ustaw automatycznie na **N** (domyślnie 5). Zmiana idzie **od razu na eBay** (wymaga połączonego konta).
- **Auto-przypisanie (po SKU):** nieprzypisane oferty łączy z naszym produktem po **SKU** (`ebay_offers.sku` ↔ `Product.product_code`, znormalizowane; **wszystkie** produkty, też wyłączone). Tylko mapowanie w bazie — **nie dotyka eBay**, więc działa też bez połączonego konta. Ręczne przypisania nietykalne.
- Obie wykonywane **cronem** (`ebay:auto-actions`) oraz przyciskiem „Uruchom teraz"; te uruchomienia trafiają do zakładki **„Logi"**. Auto-przypisanie po SKU odbywa się też przy „Pobierz oferty" (mapowanie w tle, bez osobnego wpisu w logach).

### 3.7. Logi automatycznych akcji (tab „Logi")
- Dziennik tego, co reguły faktycznie zrobiły — **jeden wiersz = jedna oferta** (podniesiona / przypisana / błąd).
- Kolumny: **Czas · Akcja · Źródło · Oferta (tytuł/SKU) · Rynek · Efekt · Status · Link** do aukcji.
- **Akcja** = `Auto-restock` (Efekt: `0 → N szt.`) lub `Auto-przypisanie` (Efekt: `→ kod produktu · nazwa`).
- **Źródło** = skąd akcja: `Cron` (`ebay:auto-actions`), `Ręcznie` („Uruchom teraz"), `Po pobraniu` (`ebay:sync-offers`).
- Filtr statusu (Wszystkie / OK / Błąd) + wyszukiwarka (tytuł / SKU / ItemID) + paginacja. Błędy pokazują treść wyjątku.
- Tabela `ebay_action_logs` (migawka: tytuł, SKU, rynek, stan przed/po; produkt dociągany relacją — nazwa PL).

---

## 4. Komendy CLI
```bash
php artisan ebay:sync-offers [--marketplace=EBAY_DE]   # pobierz oferty (+auto-match SKU +auto-restock)
php artisan ebay:auto-actions                          # auto-przypisanie (SKU) + auto-restock (do crona)
```
Prod: PHP = `/usr/local/php83/bin/php`.

**Cron (auto-restock cyklicznie), np. co godzinę** — `crontab -e`:
```
0 * * * * cd ~/domains/pim.bsplate.eu/PIM && /usr/local/php83/bin/php artisan ebay:auto-actions >> storage/logs/ebay-auto.log 2>&1
```

---

## 5. Wdrożenie na prod (`pim.bsplate.eu`)

Prod nie ma git/composer/npm → **paczki ZIP + SSH**. Ścieżka: `~/domains/pim.bsplate.eu/PIM`.

```bash
# 1) z LOKALNEGO kompa (PowerShell/CMD — NIE w sesji SSH!):
scp "C:\Users\...\ebay-*.zip" admin@5.196.81.23:~/domains/pim.bsplate.eu/PIM/

# 2) na serwerze:
cd ~/domains/pim.bsplate.eu/PIM
unzip -o ebay-*.zip
/usr/local/php83/bin/php artisan migrate            # gdy paczka ma migracje
/usr/local/php83/bin/php artisan optimize:clear
```
+ **Ctrl+Shift+R** w przeglądarce (front to Vue — inaczej stary interfejs).

**Trasy** (gdy dochodzą nowe) — najprościej dopisać komendą (bez nano):
```bash
cat >> routes/crafter.php << 'ROUTES_EOF'

Route::middleware(['crafter.base', 'auth', 'crafter.verified'])->prefix('admin')->name('crafter.')->group(function () {
    // … tu Route::post/get eBay …
});
ROUTES_EOF
```

### ⚠️ Pułapki wdrożenia (sprawdzone boleśnie)
- **`scp` uruchamiaj z LOKALNEGO kompa**, nie w sesji SSH (w SSH `scp D:\...` → „Could not resolve hostname d").
- **`nano` w webowym terminalu DirectAdmin bezużyteczny** — `Ctrl+W` zamyka **kartę przeglądarki**, nie „szukaj". Zamiast nano: `cat >> … << 'EOF'`.
- **Front `public/build` = monolit** — jeden bundle całego interfejsu; wgranie wciąga też zmiany innych modułów. Buduj ze spójnego stanu.
- **`EbayOffer::truncate()` przed re-syncem** czyści oferty (potrzebne, gdy zmienia się klucz np. marketplace) — **ale kasuje ręczne mapowania** (auto-match po SKU je odtworzy).
- `optimize:clear` po każdej zmianie tras/kodu; potem twardy refresh.

---

## 6. Zaległości / TODO

- 🔑 **Rotacje kluczy** (bezpieczeństwo): OpenAI key (był w `config/google.php`), eBay **Cert ID** (`PRD-…b51b`, pokazywany wielokrotnie), Auth'n'Auth **user token** (niepotrzebny — „Revoke a Token").
- **~2142 / 3338 ofert bez mapowania** — auto-przypisanie po SKU (reguła w „Automatyczne akcje") łapie tylko te z `sku == product_code`; reszta ma inny format SKU. Do zbadania: alternatywny klucz (EAN / fragment kodu) — dziś świadomie **tylko SKU**.
- **„Ustaw wg magazynu"** (4. tryb ilości jak w BaseLinker) — pominięty, bo `Product` nie trzyma stanu magazynowego; dodać, gdy będzie źródło stanu.
- **Cron** `ebay:sync-offers` (cykliczne odświeżanie stanów) — do dołożenia w crontab, jeśli auto-restock ma reagować szybko.
- Operacje = rozszerzalny zestaw — kolejne akcje (np. auto-obniżka ceny przy zaleganiu) dokładamy w tym samym wzorcu.

---

*Powiązane: `ebay-scrap-narzedzie-pim.md` (monitoring konkurencji — Argo Scope). Pamięć projektu: `pim_ebay_sell_module.md`.*
