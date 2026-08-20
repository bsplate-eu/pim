# Warstwa Admin — pełny zapis prac

Sesja 2026‑08‑19 / 20. Tag w repo: **`admin-2026-08-20`**.
Dokumentacja referencyjna (podzielona tematycznie): [`docs/admin/`](docs/admin/README.md).
Ten plik to **chronologiczny log całej pracy** — co zastałem, co zrobiłem, co sprawdziłem i czym się sparzyłem.

---

## 1. Punkt wyjścia

Zlecenie zaczęło się od rozpoznania („zobacz co tu mamy w temacie ról i zarządzania użytkownikami"), a po audycie przeszło w: *„nie mogę teraz w panelu zarządzać uprawnieniami użytkowników, a jest to teraz ważne"*.

### Co zastałem

Mechanizm: **Spatie laravel‑permission**, guard `crafter`, model `App\Models\AdminUser`. `App\Models\User` (guard `web`) to relikt — panel go nie używa.

| | stan zastany |
|---|---|
| role | 2: `Administrator` (67 uprawnień), `Guest` (1) |
| uprawnienia | 67, schemat `crafter.<zasób>.<akcja>` |
| użytkownicy na prod | 3 |
| kontrola dostępu | tylko stare moduły Craftera (Requesty z `Gate::allows`) |

**Moduły Argo — Argo HQ, Connect, Scope, Task, Mail, Tłumaczenia v2, Kasa, KSeF — nie miały ŻADNEJ kontroli.** Chronił je wyłącznie `auth` + `crafter.verified`. Ostatnie migracje uprawnień pochodziły z kwietnia i maja 2025 (kategorie, ai_tools); wszystko zbudowane później nie miało nawet zdefiniowanych uprawnień.

Liczby z audytu: 95 na 99 klas Request miało bramkę `Gate` — ale wszystkie dotyczyły starych modułów. Nowe moduły nie miały w ogóle klas Request. `authorize()` wołane w 5 kontrolerach na 81.

### Dlaczego nie dało się tym zarządzać

1. Grupa menu „System" (Role, Lokalizacja, Ustawienia) miała w `Sidebar.vue` na sztywno **`v-if="false"`** — ekrany działały, ale nie było do nich linku.
2. `RoleController` miał tylko `index/edit/update` — **nowej roli nie dało się założyć z panelu**, tylko SQL‑em.
3. Macierz uprawnień pokazywała surowe klucze (`crafter.integration-product.destroy`), bo plik tłumaczeń mapuje klucz na samego siebie.

---

## 2. Co zbudowałem

### 2.1 Uprawnienia modułowe

9 uprawnień `crafter.module.*`: `costs`, `kasa`, `ksef`, `connect`, `marketplace`, `scope`, `task`, `mail`, `translations`.

Zamiast dopisywać bramki do ~50 kontrolerów — jedno miejsce mapujące **prefiks nazwy trasy** na uprawnienie:

- `config/module-permissions.php` — mapa 19 prefiksów + sekcja `except` (push, kursy NBP, PWA home, powiadomienia),
- `app/Http/Middleware/EnsureModuleAccess.php` — wpięty na końcu grupy `crafter.base`, czyli obejmuje wszystkie trasy panelu.

Reguła: **wygrywa najdłuższy pasujący prefiks**, więc `connect.marketplace.` przebija `connect.`. Brak dopasowania → przepuszcza (stare moduły mają własne bramki). Brak zalogowanego → przepuszcza (od tego jest `auth`).

Migracja `2026_08_19_100000_add_module_permissions.php` nadaje komplet **wszystkim rolom poza „Guest"** — nikt nie traci dostępu w dniu wdrożenia; zawężanie robi się świadomie w macierzy.

### 2.2 Menu Admin

Pięć osobnych pozycji najwyższego poziomu zwinięte w jedną grupę (wg makiety użytkownika):

```
Admin
 · AI Tools
 ▸ Tłumaczenia               Matryca / review / Logi / Ustawienia
 ▸ Użytkownicy i uprawnienia Users / Role / Uprawnienia (macierz)
 ▸ Poczty (SMTP)             Mail SMTP / Szablony maili / Logi poczty
 ▸ System                    Lokalizacja / Ustawienia
```

Grupa znika w całości, gdy użytkownik nie ma żadnego z ośmiu obejmowanych uprawnień. Każda podgrupa i pozycja ma własną bramkę `v-can`. Przy okazji nałożyłem bramki na wszystkie grupy modułowe (Argo HQ, PIM, Connect, Scope, Task, Mail, Tłumaczenia) i usunąłem osierocone importy ikon.

### 2.3 Pełny CRUD ról

`RoleController` dostał `create/store/destroy` oraz zmianę nazwy w `update`. Trasy: `roles/create`, `POST roles`, `DELETE roles/{role}`.

- zakładając rolę można **skopiować uprawnienia z istniejącej**,
- nowa rola zawsze dostaje `crafter` (wstęp do panelu),
- **blokada:** ról systemowych (`Administrator`, `Guest`) nie można usunąć ani przemianować,
- **blokada:** roli z przypisanymi użytkownikami nie można usunąć (komunikat podaje ilu ich jest),
- lista pokazuje licznik uprawnień i awatary osób.

### 2.4 Etykiety PL w macierzy

`app/Support/PermissionLabels.php` składa etykietę z nazwy zasobu i akcji (`RESOURCES` + `ACTIONS`), a wyjątki trzyma w `OVERRIDES`. Mapa idzie do frontu propem `permissionLabels`, czyta ją hook `usePermissionLabels.ts`. Klucz bez wpisu wraca surowy — nowe uprawnienie widać od razu. Surowy klucz siedzi w tooltipie.

### 2.5 Bezpiecznik przed samo‑zablokowaniem

`app/Support/PermissionLockoutGuard.php`. Przed zapisem symuluje stan docelowy uprawnień autora zmiany. Jeśli straciłby `crafter.role.index`, `role.edit`, `permission.index` lub `permission.edit` — zapis leci `ValidationException`, nic się nie zmienia. Odebranie tych praw **komuś innemu** jest dozwolone.

Wcześniej pilnował tego wyłącznie `confirm()` w JS, czyli nic — jeden klik i nikt nie mógł już wejść w role.

### 2.6 Skrzynki Argo Mail per użytkownik

Problem: `crafter.module.mail` jest zero‑jedynkowe — kto miał wstęp do poczty, widział **wszystkie 7 skrzynek**, czytał cudzą korespondencję, mógł wysłać maila z dowolnego adresu firmowego i skasować skrzynkę.

Zasada:

| rola ma `crafter.mail-account.all` | widzi |
|---|---|
| tak | wszystkie skrzynki |
| nie | wyłącznie przypisane imiennie |

Skrzynki przypisuje się **osobie, nie roli** — dwie osoby na tym samym stanowisku zwykle obsługują różne adresy.

- tabela `mail_account_admin_user` + uprawnienie `crafter.mail-account.all` (migracja `2026_08_19_110000`), nadane wszystkim rolom poza „Guest",
- `Account::scopeVisibleTo()` zawęża listy w `MailController::index`, `AccountController`, `AiToolsMailController` i **widoku mobilnym** — ten ostatni czytał maile ze wszystkich skrzynek zupełnie bez filtra,
- `EnsureMailAccountAccess` (alias `mail.account`) na grupie tras `argo-mail` pilnuje pojedynczych żądań: `{account}`, `{message}`, `account_id` w body — kilkanaście endpointów zamiast wklejania warunku do każdej metody,
- `MailController::send` dodatkowo szuka konta przez `visibleTo()->findOrFail()` — nie da się wysłać „z cudzej" skrzynki po podmianie `account_id`.

UI: **Users → edycja → karta „Skrzynki Argo Mail"**. Najpierw zrobiłem multiselect; przy siedmiu skrzynkach okazał się nieczytelny (pole wyglądało na puste), więc przerobiłem na listę z checkboxami — licznik „Zaznaczone: X z Y", skróty „Zaznacz wszystkie / Wyczyść", plakietka „nieaktywna", podpowiedź zmieniająca się zależnie od stanu.

---

## 3. Dziury bezpieczeństwa znalezione po drodze

| # | co | skala |
|---|---|---|
| 1 | **3 grupy tras bez `auth`** | `/admin/translation-phrases`, `-logs`, `-settings` oddawały **200 bez logowania na produkcji** |
| 2 | `InviteUserRequest::authorize()` → `return true` | każdy zalogowany mógł zaprosić konto i nadać mu **rolę Administrator** |
| 3 | `IndexAdminUserRequest` — `return true` **przed** `Gate::allows` | martwy kod; lista użytkowników dla każdego zalogowanego |
| 4 | `UpdatePermissionRequest` — reguła `roles.permissions.*` | ścieżka nigdy nie istniała w payloadzie, nic nie było walidowane |

### Szczegóły #1

Zmierzone na produkcji **przed** poprawką:

```
GET /admin/translation-phrases   → 200 bez logowania
GET /admin/translation-logs      → 200 bez logowania
GET /admin/translation-settings  → 200 bez logowania
GET /admin/categories            → 403
GET /admin/ai-tools              → 403
```

Cała matryca tłumaczeń — nazwy produktów, logi, ustawienia — czytelna dla każdego, kto znał adres. Kontrolery tego modułu nie mają klas Request z `Gate`, więc nic tego nie zatrzymywało; `categories` i `ai-tools` oddawały 403 wyłącznie dlatego, że **ich** Requesty sprawdzają uprawnienia.

Endpointy POST (`auto-translate-bulk`, `approve-bulk`, `translate-missing`) wymagały tokenu CSRF, ale ten był do wyjęcia z tej samej otwartej strony — `HandleInertiaRequests` wysyła `csrf_token` w propsach.

Po poprawce wszystkie trzy oddają **302** na logowanie.

---

## 4. Incydent: częściowy deploy

**Objaw.** W trakcie prac z menu produkcji zniknęły Argo HQ, Connect, Scope, Task, Mail i Tłumaczenia.

**Przyczyna.** Commit `f0f2136` („Sidebar Argo Connect: trzy podgrupy…") powstał w **równoległej sesji** pracującej w tym samym katalogu roboczym. Zabrał `Sidebar.vue` z 22 bramkami `v-can="crafter.module.*"` i przebudowany `public/build` — ale **bez backendu, który te uprawnienia zakłada**. Migracja, config i middleware zostały niezacommitowane na dysku.

Uprawnienia nie istniały w bazie → `can()` = `false` → `v-can` usuwał pozycje z menu.

**Zakres.** Nic nie było zablokowane — middleware egzekwujący też nie pojechał, więc strony dalej otwierały się z adresu. Zniknęły wyłącznie pozycje w menu.

**Diagnoza** (po SSH na prod): migracje kończyły się na `2026_08_18_120000`, `config/module-permissions.php`, `EnsureModuleAccess.php` i `PermissionLabels.php` — BRAK, a `Sidebar.vue` miał 22 bramki.

**Naprawa.** `63b440e` — sama migracja, 88 linii, jeden plik, zero zależności.

---

## 5. Chronologia wdrożeń

| # | commit | zawartość | weryfikacja po deployu |
|---|---|---|---|
| 1 | `63b440e` | migracja 9 uprawnień | migracja `Ran` (batch 42); oba konta admin mają komplet 9 |
| 2 | `e81a63a` | `auth` na 3 grupach tras | trzy trasy 200 → **302**, `login` dalej 200 |
| 3 | `ed5e74f` | menu Admin, `EnsureModuleAccess`, CRUD ról, etykiety, bezpiecznik, 3 dziury | pliki na miejscu, mapowanie tras potwierdzone na żywo, log bez nowych błędów |
| 4 | `176fd82` | skrzynki Argo Mail | migracja `Ran` (batch 43), tabela pivot jest, Administrator = 7 skrzynek, Guest = 0 |
| 5 | `d15dc79` | lista z checkboxami | chunk publicznie dostępny, zawiera nowy UI, po starym dropdownie ani śladu |
| 6 | `29e0f87` | dokumentacja `docs/admin/` | — |

Wdrożenie idzie automatem: `git push` → cron co 5 min → `wdroz-pim.sh` (pull + lint + `migrate` + cache). Realny czas od pusha do produkcji: **2–4 min**.

### Weryfikacja mapowania tras na produkcji

```
crafter.connect.orders.index                    → crafter.module.connect
crafter.connect.marketplace.ebay.listing.index  → crafter.module.marketplace
crafter.ksef.pareto                             → crafter.module.ksef
crafter.products.index                          → przepuszcza (stary moduł)
crafter.dashboard                               → przepuszcza
```

---

## 6. Co faktycznie przetestowałem

### Bramki modułowe

Rola z dostępem **tylko do Argo Connect**:

```
dashboard                          200
connect/orders                     200
connect/marketplace/ebay/schemes   403
ksef/pareto                        403
cost-planner                       403
translation-phrases                403
argo-task/groups/create            403
argo-mail                          403
scope/rumuni                       403
admin-users / roles / permissions  403
products                           403
```

Rola **tylko z Tłumaczeniami** — odwrotnie: `translation-phrases` i `-settings` na 200, reszta 403. Administrator — wszystko 200.

### CRUD ról

- utworzenie roli z kopiowaniem uprawnień → rola powstała z właściwą liczbą uprawnień,
- duplikat nazwy → odrzucony, druga rola nie powstała,
- usunięcie roli systemowej → **zablokowane**, rola nadal istnieje,
- usunięcie roli z użytkownikiem → **zablokowane**,
- usunięcie roli pustej → udane.

### Bezpiecznik

Próba pozostawienia roli `Administrator` z samym `crafter` (czyli odebranie sobie zarządzania uprawnieniami) → zapis odrzucony, rola nadal ma komplet 76 uprawnień.

### Skrzynki Argo Mail

Rola bez `mail-account.all`, przypisane konto 1:

```
/admin/argo-mail → widzi tylko blacksteelplates@gmail.com
wiadomość z konta 1   → 200
wiadomość z konta 2   → 403
```

Administrator dalej widzi obie. Formularz edycji oddaje `mailAccountIds: [1]` — zaznaczenia zapisują się i wczytują.

Zapis przez UI: zaznaczenie dwóch skrzynek → obie w bazie. Odebranie wszystkich („Wyczyść") → przypisania wyczyszczone. Kontrola negatywna: żądanie **bez** znacznika `sync_mail_accounts` (inline aktywacja z listy) → przypisania nietknięte.

### Smoke test

16 ekranów na 200 przed każdym wypchnięciem: dashboard, roles, roles/create, permissions, admin-users, admin-users/create, argo-mail, argo-mail/accounts, argo-mail/settings, m/mail, connect/orders, cost-planner, ksef/pareto, translation-phrases, products, ai-tools.

---

## 7. Inwentarz plików

### Nowe

```
app/Http/Middleware/EnsureModuleAccess.php
app/Http/Middleware/EnsureMailAccountAccess.php
app/Http/Requests/Admin/Roles/StoreRoleRequest.php
app/Http/Requests/Admin/Roles/DestroyRoleRequest.php
app/Support/PermissionLabels.php
app/Support/PermissionLockoutGuard.php
config/module-permissions.php
database/migrations/2026_08_19_100000_add_module_permissions.php
database/migrations/2026_08_19_110000_create_mail_account_admin_user.php
resources/js/crafter/hooks/usePermissionLabels.ts
resources/js/crafter/Pages/Roles/Create.vue
resources/js/crafter/Pages/AdminUser/Components/MailAccountsCard.vue
docs/admin/{README,01-uprawnienia-modulowe,02-role-i-uprawnienia,03-skrzynki-argo-mail,04-wdrozenie-i-pulapki}.md
```

### Zmienione

```
app/Http/Kernel.php                                      middleware + alias mail.account
routes/crafter.php                                       auth na 3 grupach, trasy ról, mail.account
app/Http/Controllers/Admin/Roles/RoleController.php      CRUD + blokady
app/Http/Controllers/Admin/Permissions/PermissionController.php   bezpiecznik + etykiety
app/Http/Controllers/Admin/AdminUser/AdminUserController.php      skrzynki w formularzu
app/Http/Controllers/Admin/Mail/MailController.php       visibleTo w index i send
app/Http/Controllers/Admin/Mail/AccountController.php    visibleTo
app/Http/Controllers/Admin/AiAgents/AiToolsMailController.php     visibleTo
app/Http/Controllers/Admin/MobileController.php          visibleTo + filtr maili
app/Models/AdminUser.php                                 relacja mailAccounts
app/Models/Mail/Account.php                              scopeVisibleTo, visibleIdsFor, isVisibleTo
app/Http/Requests/Admin/AdminUser/{Index,Store,Update}AdminUserRequest.php
app/Http/Requests/Admin/Auth/InviteUserRequest.php
app/Http/Requests/Admin/Permissions/UpdatePermissionRequest.php
resources/js/crafter/Components/Sidebar.vue              grupa Admin + bramki
resources/js/crafter/Pages/Roles/{Index,Edit,Permission}.vue
resources/js/crafter/Pages/Permissions/Components/PermissionTableRow.vue
resources/js/crafter/Pages/AdminUser/{Create,Edit,Form}.vue, types.d.ts
```

---

## 8. Pułapki warsztatowe

### `public/build` przy równoległych sesjach

Vite kompiluje **cały** katalog roboczy, więc build niesie też cudze niedokończone Vue. Wysłanie samego JS bez odpowiadającego mu PHP daje dokładnie awarię z rozdziału 4, tylko w cudzym module.

Przed commitem: `git diff --numstat <plik>`. Podejrzanie duży diff w pliku, którego ledwo dotknąłeś = ktoś w nim siedzi. Tu tak było z `MailController.php` (+376/−26, moje było 8 linii) i `routes/crafter.php`.

### Chirurgiczny commit z dzielonego pliku

Użyty trzykrotnie w tej sesji:

```bash
cp <plik> <scratchpad>/kopia     # odłóż wersję roboczą
git checkout -- <plik>           # wróć do wersji z repo
# nanieś TYLKO swoje zmiany
git add <plik> && git commit
cp <scratchpad>/kopia <plik>     # przywróć wersję roboczą
```

Po ostatnim kroku git widzi jako niezacommitowane wyłącznie zmiany drugiej sesji. To samo dla plików `.vue` **przed** `npm run build` — odłóż cudze, zbuduj, zacommituj, przywróć. Za każdym razem potem sprawdzałem, że ich praca wróciła w całości.

### Panel to SPA — samo klikanie nie odświeży kodu

Po deployu użytkownik z otwartą od dawna zakładką **dalej wykonuje bundle sprzed wdrożenia** — Inertia robi przejścia po XHR i nie pobiera JS ponownie. Potrzebne **F5**. Samo F5 wystarczy, bo HTML leci z `cache-control: no-cache, private`.

Jak odróżnić „przeglądarka" od „serwer" (bez logowania):

```bash
curl -s https://pim.bsplate.eu/admin/login | grep -o 'assets/index-[a-z0-9]*\.js'   # co jest w HTML
# porównaj z manifest.json → resources/js/crafter/index.ts → file
curl -s https://pim.bsplate.eu/build/assets/<chunk>.js | grep -c '<nowy napis>'
```

### Pusta tablica ginie w FormData

Formularz użytkownika niesie avatar → Inertia serializuje do `FormData`, a pusta tablica nie zostawia tam klucza. Bez zabezpieczenia nie dałoby się odebrać *wszystkich* skrzynek. Stąd jawny znacznik `sync_mail_accounts`, który przy okazji chroni przypisania przed akcjami inline z listy użytkowników.

### `withCount` po `select`

W `RoleController::index` `withCount('permissions')` musi iść **po** `select(['id','name'])` — `select()` nadpisuje listę kolumn i wywala podzapytanie liczące. Złapane dopiero w przeglądarce (kolumna „Uprawnienia" wychodziła pusta).

### Diagnostyka produkcji

Shell `admin` **nie ma dostępu do MySQL** (bazy tylko przez panel), ale ma PHP 8.3 i `artisan` — stan bazy czyta się tinkerem. Heredoc `<<'R'` plus `--execute='...'` w apostrofach ratują przed piekłem escapowania backslashy w namespace'ach (inaczej `T_NS_SEPARATOR`).

---

## 9. Stan produkcji po wdrożeniu

Role: `Administrator`, `Guest`.

| konto | rola | moduły | skrzynki |
|---|---|---|---|
| info@bsplate.eu | Administrator | wszystkie 9 | wszystkie 7 (przez `mail-account.all`) |
| m.kowalik@b2bpareto.pl | Administrator | wszystkie 9 | wszystkie 7 |
| m.zajac@oslonypareto.pl | Guest | wg macierzy | wg przypisań (0 na start) |

Skrzynki na produkcji: `a.sliwinski@b2bpareto.pl`, `bok@oslonypareto.pl`, `info@bsplate.de`, `info@bsplate.eu`, `marketplace@bsplate.eu`, `oslonypareto@gmail.com`, `shop@hgshop24.de`.

Log produkcyjny bez błędów pochodzących z tej warstwy.

---

## 10. Co zostało

### Do dokończenia

- **`MailController::emptyTrash()` i `emptySpam()`** nie mają zawężenia `visibleTo()` — to metody z trwającego portu Argo Mail, których nie było w repo. Dodać, gdy tamta praca wejdzie:
  ```php
  $accountIds = Account::query()->visibleTo(auth()->user())->pluck('id');
  ```

### Decyzje dla użytkownika

- **m.zajac (Guest)** ma teraz 403 na modułach, nie tylko puste menu. Nadanie dostępu: Admin → Uprawnienia (macierz) → kolumna Guest.
- **„Poczty (SMTP)"** w menu — wpisane dosłownie z makiety; jeśli miało być „Poczta", to jedna litera.
- **Tag `admin-2026-08-20`** wskazuje na commit z dokumentacją, a `main` przesunął się w międzyczasie o 4 commity drugiej sesji (eBay kType, EAN‑y) — więc pod tagiem siedzą też ich zmiany. Da się przestawić na `d15dc79` (ostatni czysto mój), ale wtedy dokumentacja wypadnie spoza tagu.

### Rzeczy zastane, świadomie nietknięte

- `AdminUserController::show()` renderuje komponent `AdminUser/Show`, którego nie ma w `resources/js` — trasa martwa, nic do niej nie linkuje, ale wejście z adresu wywali błąd.
- `impersonalLogin` robi `Auth::login()` bez zapamiętania oryginalnego konta — nie ma powrotu do siebie inaczej niż przez wylogowanie.
- W macierzy nie da się kliknąć samego uprawnienia `crafter` — `Arr::set` nadpisuje liść folderem, więc górny wiersz „Dostęp do panelu" jest grupą obejmującą wszystko. Nieszkodliwe (nowe role dostają je automatycznie), ale mylące.
- Model `App\Models\User` (guard `web`) — relikt.

### Błędy w logu produkcyjnym spoza tej warstwy

- **BaseLinker** — `Odwołanie do pliku z nieprawidłową akcją: ProductsQuantity` (`BaselinkerController.php:51`) + ostrzeżenie o przestarzałym md5. Ok. 50 wpisów dziennie, identycznie 17 i 18 sierpnia.
- **CallMeBot WhatsApp** — `HTTP 208` przy `ksef:signal-due` i `connect:sales-report`, co godzinę. Powiadomienia nie dochodzą.
- **`strlen(): Passing null`** — kilka tysięcy linii z crona o 03:00, deprecation.
