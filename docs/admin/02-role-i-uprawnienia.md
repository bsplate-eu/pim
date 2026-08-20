# 02 — Role, uprawnienia i menu Admin

## Menu

Pięć osobnych pozycji najwyższego poziomu (AI Tools, Tłumaczenia, Użytkownicy, Poczta SMTP, System) zwinięte w jedną grupę:

```
Admin
 · AI Tools
 ▸ Tłumaczenia            Matryca tłumaczeń / Tłumaczenia (review) / Logi / Ustawienia
 ▸ Użytkownicy i uprawnienia   Users / Role / Uprawnienia (macierz)
 ▸ Poczty (SMTP)          Mail SMTP / Szablony maili / Logi poczty
 ▸ System                 Lokalizacja / Ustawienia
```

Cała grupa znika, gdy użytkownik nie ma żadnego z ośmiu uprawnień, które obejmuje (`v-can:any`). Każda podgrupa i pozycja ma własną bramkę, więc widać wyłącznie to, do czego jest dostęp.

Do 2026‑08‑19 grupa „System" miała w `Sidebar.vue` na sztywno `v-if="false"` — Role, Lokalizacja i Ustawienia były osiągalne wyłącznie z adresu. To był pierwotny powód, dla którego nie dało się zarządzać uprawnieniami z panelu.

## Role — pełny CRUD

`app/Http/Controllers/Admin/Roles/RoleController.php` miał wcześniej tylko `index/edit/update`, czyli **nowej roli nie dało się założyć inaczej niż SQL‑em**. Doszły:

| akcja | trasa |
|---|---|
| lista | `GET /admin/roles` |
| nowa rola | `GET /admin/roles/create`, `POST /admin/roles` |
| edycja uprawnień + nazwy | `GET /admin/roles/{role}/edit`, `PUT /admin/roles/{role}/update` |
| usunięcie | `DELETE /admin/roles/{role}` |

Zakładając rolę można **skopiować uprawnienia z istniejącej** — wygodny start dla wariantu „to samo co X, minus dwa moduły". Nowa rola zawsze dostaje `crafter` (wstęp do panelu), bo bez tego byłaby bezużyteczna.

### Blokady

- Ról systemowych (`Administrator`, `Guest`) **nie można usunąć ani przemianować** — stała `RoleController::PROTECTED_ROLES`, front dodatkowo wyszarza pole nazwy i chowa kosz.
- Roli **przypisanej do użytkowników nie można usunąć** — komunikat mówi ilu ich jest. Najpierw przepnij ludzi.

Lista ról pokazuje licznik uprawnień i awatary przypisanych osób.

> Gotcha: w `RoleController::index` `withCount('permissions')` musi iść **po** `select(['id','name'])`. `select()` nadpisuje listę kolumn i wywala podzapytanie liczące — licznik wychodzi wtedy pusty.

## Macierz uprawnień

`GET /admin/permissions` — wiersze to uprawnienia, kolumny to role. Wiersze z ikoną folderu przełączają całą sekcję naraz (stan pośredni = część zaznaczona). Zapis przyciskiem u góry.

### Etykiety PL

Plik `resources/translations/permissions/permission_translations.json` mapuje klucz na samego siebie, więc macierz pokazywała surowe `crafter.integration-product.destroy`. Zamiast utrzymywać ręcznie 80 wpisów, etykietę składa `app/Support/PermissionLabels.php`:

- `RESOURCES` — zasób → nazwa („admin-user" → „Użytkownicy"),
- `ACTIONS` — akcja → czasownik („destroy" → „Usuwanie"),
- `OVERRIDES` — pełne klucze, których nie da się złożyć (moduły Argo, poczta SMTP, `crafter` → „Dostęp do panelu").

Mapa idzie do frontu jako prop `permissionLabels` i czyta ją hook `resources/js/crafter/hooks/usePermissionLabels.ts`. Klucz bez wpisu wraca surowy — nowe uprawnienie widać od razu, nawet zanim dostanie etykietę. Surowy klucz jest w tooltipie (`title`), co ułatwia szukanie w kodzie.

### Znana pułapka widoku

Drzewo buduje się przez `Arr::set($tree, $permission, $permission)`. Klucz `crafter` jest jednocześnie **uprawnieniem** i **korzeniem** wszystkich pozostałych, więc przy wstawianiu `crafter.role.index` Laravel nadpisuje string tablicą. Efekt: górny wiersz **„Dostęp do panelu" to folder obejmujący wszystko**, a samego uprawnienia `crafter` nie da się w macierzy ani nadać, ani odebrać.

Nic się przez to nie psuje — role zakładane przyciskiem „Dodaj rolę" dostają `crafter` automatycznie, a bulk‑toggle zbiera tylko liście, więc nie potrafi go zgubić. Ale nie należy szukać tego checkboxa.

## Bezpiecznik przed samo‑zablokowaniem

`app/Support/PermissionLockoutGuard.php`.

Wcześniej odznaczenie sobie prawa do zarządzania uprawnieniami pilnował wyłącznie `confirm()` w JS — czyli nic. Jeden zapis i nikt (łącznie z autorem) nie mógł już wejść w role; odkręcenie wymagało SQL‑a.

Teraz przed zapisem symulowany jest stan docelowy: uprawnienia bezpośrednie użytkownika + suma jego ról, gdzie rola zmieniana bierze nową listę. Jeśli w wyniku brakuje któregokolwiek z `crafter.role.index`, `crafter.role.edit`, `crafter.permission.index`, `crafter.permission.edit` — zapis leci `ValidationException` z nazwami utraconych uprawnień, nic się nie zmienia. Odebranie ich **komuś innemu** jest dozwolone.

Wpięte w `RoleController::update` i `PermissionController::update`.

## Załatane dziury

Przy okazji, wszystko potwierdzone testem:

| co | było |
|---|---|
| `IndexAdminUserRequest` | `return true;` **przed** `Gate::allows` — martwy kod, lista użytkowników dostępna dla każdego zalogowanego |
| `InviteUserRequest` | `return true` — każdy zalogowany mógł zaprosić nowe konto i nadać mu **rolę Administrator**; teraz wymaga `crafter.admin-user.create`, a rola walidowana przez `exists:roles,name` |
| `UpdatePermissionRequest` | reguła `roles.permissions.*` — ścieżka nigdy nie istniała w payloadzie, więc nic nie było walidowane; teraz `roles.*.id` (`exists`) i `roles.*.permissions.*` (`exists`) |
| trzy grupy tras | miały tylko `crafter.base`, bez `auth` — patrz [04‑wdrozenie‑i‑pulapki.md](04-wdrozenie-i-pulapki.md) |

## Rzeczy zastane, nietknięte

- `AdminUserController::show()` renderuje komponent `AdminUser/Show`, którego nie ma w `resources/js`. Nic do niego nie linkuje (lista używa `${resource_url}/edit`), więc trasa jest martwa — ale wejście z adresu wywali błąd.
- `impersonalLogin` robi `Auth::login($adminUser)` bez zapamiętania oryginalnego konta — nie ma powrotu do siebie inaczej niż przez wylogowanie.
- Model `App\Models\User` (guard `web`, tabela `users`) to relikt — panel używa `AdminUser`.
