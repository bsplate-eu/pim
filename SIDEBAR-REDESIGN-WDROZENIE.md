# Sidebar — Redesign + Zwijanie Lewej Kolumny (dziennik wdrożenia)

> Dziennik prac nad nowym wyglądem sidebara PIM oraz podłączeniem trybu zwijania (collapsed mode).
>
> - Data: **2026-05-20**
> - Projekt: `D:\laragon\www\PIM`
> - Adres lokalny: `http://pim.test` (`APP_ENV=local`)
> - Źródło designu: `+importy/argo-connect/sidebar-design-export.md` (oraz wzorzec menu w `+importy/argohq-package/`)

---

## 1. Cel

1. **Argo Task ma być zawsze zwinięte** przy starcie (pierwotne zadanie).
2. **PIM ma wyglądać „po nowemu"** — granatowy sidebar wg dokumentu designu (port z `adminargosite` / `C:\laragon\www\pim`).
3. **Zwijanie całej lewej kolumny** (collapsed mode) — przycisk chowający sidebar do wąskiej kolumny z ikonami.

---

## 2. Diagnoza (dlaczego „nic się nie zmieniało")

- Projekt korzysta z **produkcyjnego buildu Vite** (`public/build/manifest.json` istnieje, brak `public/hot`). Zmiany w plikach `.vue` **nie są widoczne** dopóki nie przebuduje się assetów.
- `npm` / `node` **nie są w PATH** PowerShella ani Git Bash. Node znaleziony dopiero w instalacji Laragona na dysku C:
  - `C:\laragon\bin\nodejs\node-v18\node.exe` (Node v18.8.0)
- `C:\laragon\www\pim` (źródło wskazane w dokumencie designu) **nie istnieje** na tej maszynie — kopia 1:1 niemożliwa. Wzorzec menu (`Sidebar.vue`, 537 linii) znaleziony lokalnie w `+importy/argohq-package/`, ale **bez** komponentów składowych (`SidebarSubGroup`, hook `useSidebarActive`, itd.) — te trzeba było napisać od zera na podstawie opisu w dokumencie.
- Po przebudowie „nadal stare" = **cache przeglądarki** (Inertia to SPA — klikanie w menu nie przeładowuje JS/CSS). Wymagany twardy reload **Ctrl+Shift+R**.

---

## 3. Środowisko / komenda buildu

`npm` nie jest w PATH — build odpalany z jawną ścieżką do Node z Laragona:

```bash
cd "/d/laragon/www/PIM"
export PATH="/c/laragon/bin/nodejs/node-v18:$PATH"
npm run build
```

(Bez `export PATH` build pada z `'"node"' is not recognized` — wrapper `vite` woła `node` z PATH.)

Alternatywa: Laragon → Menu → Node.js / Terminal, następnie `npm run build` w katalogu projektu.

---

## 4. Konfiguracja kolorów (Tailwind)

**`tailwind.config.js`** — dodana paleta `sidebar` (główny granat `#15275a`):

```js
sidebar: {
    '50':  '#f1f3f8',
    '100': '#dde2ed',
    '200': '#bcc5d9',
    '300': '#94a1c1',
    '400': '#6c7eaa',
    '500': '#3e5288',
    '600': '#15275a', // granat (główny kolor sidebara)
    '700': '#11204a',
    '800': '#0d193b',
    '900': '#08112a',
},
```

Używana w komponentach jako `text-sidebar-600`, `text-sidebar-600/90`, oraz arbitralnie `bg-[#15275a]/10`, `ring-[#15275a]/20`.

---

## 5. Pliki utworzone

| Plik | Opis |
|---|---|
| `resources/js/crafter/hooks/useSidebarActive.ts` | Hook provide/inject. `useSidebarActiveProvider(initialOpen)` → `{ isOpen, hasActive }`; `useSidebarActiveConsumer(activeRef)` rejestruje liść u rodzica. Grupa auto-otwiera się gdy zawiera aktywny link; propagacja rekurencyjna w górę do top-levelu. |
| `resources/js/crafter/Components/Sidebar/SidebarSubGroup.vue` | Zagnieżdżona podgrupa — mixed case, `text-sm`, chevron rotujący, lewy border `border-gray-300 pl-2`, ring na aktywnej. |
| `resources/js/crafter/Components/Sidebar/SidebarSubGroupNavLink.vue` | Bezpośredni `<Link>` wyglądający jak nagłówek SubGroup (`pl-7 text-sm`). |

---

## 6. Pliki przepisane / zmienione

### `resources/js/crafter/Components/Sidebar/SidebarGroup.vue` (rewrite)
- Nowy prop `icon` (komponent Heroicons).
- `useSidebarActiveProvider(props.open)` → auto-rozwijanie aktywnej ścieżki.
- Nagłówek **UPPERCASE**, chevron rotujący 90° (`rotate-90` przy otwarciu).
- Ring na aktywnej grupie: `bg-[#15275a]/10 ring-1 ring-[#15275a]/20`.
- **Collapsed mode:** `inject("sidebarCollapsed")` + `inject("expandSidebar")`. Gdy zwinięty: tylko ikona (`justify-center`), tekst i chevron ukryte, `title` jako tooltip. Klik w ikonę → `expandSidebar()` + otwiera grupę.
- `v-auto-animate` na kontenerze (płynne rozwijanie).

### `resources/js/crafter/Components/Sidebar/SidebarItem.vue` (rewrite)
- Kolory granatowe (`text-sidebar-600/90`), aktywny liść `bg-gray-200`.
- `useSidebarActiveConsumer(active)` — zgłasza swój stan aktywności rodzicowi (do auto-rozwijania).
- **Collapsed mode:** `inject("sidebarCollapsed")`. Gdy zwinięty: ikona wyśrodkowana, tekst ukryty, `title` = tekst z slotu (`slotText`).

### `resources/js/crafter/Components/Sidebar/Sidebar.vue` (wrapper, rewrite)
- **Jasna stopka** (`bg-gray-50`, `text-sidebar-600`) zamiast ciemnej (`bg-primary-600`).
- `inject("sidebarCollapsed")` — logo i stopka wyśrodkowane gdy zwinięty, dane usera ukryte.
- Prop `forceExpanded` — mobilny dialog re-`provide`uje `sidebarCollapsed = false`, żeby na mobile zawsze był pełny.
- `overflow-hidden` / `overflow-x-hidden` — przycinanie zawartości przy wąskiej kolumnie.

### `resources/js/crafter/Layouts/Authenticated.vue` (rewrite)
- `const isCollapsed = ref(...)` inicjowany z `localStorage` (klucz `crafter.sidebarCollapsed`).
- `provide("sidebarCollapsed", isCollapsed)` + `provide("expandSidebar", () => isCollapsed.value = false)`.
- **Przycisk toggle** w górnym pasku (desktop, `hidden md:inline-flex`): `«` (`ChevronDoubleLeftIcon`) gdy rozwinięty / `»` (`ChevronDoubleRightIcon`) gdy zwinięty.
- Dynamiczna szerokość: sidebar `md:w-16` / `md:w-64`, content `md:pl-16` / `md:pl-64`, z `transition-all duration-200`.
- `watch(isCollapsed)` → zapis do `localStorage` (persystencja stanu).
- Mobilny `<Sidebar :force-expanded="true" />`.

### `resources/js/crafter/Components/index.ts`
- Dodany import + eksport `SidebarSubGroup` oraz `SidebarSubGroupNavLink`.

### `resources/js/crafter/Components/Sidebar.vue` (treść menu)
- Grupa **Argo Task**: `:open="false"` (zawsze zwinięta na start) + `:icon="ClipboardDocumentListIcon"`.
- (W trakcie sesji dochodziły też sekcje Argo HQ / Argo Connect z ikonami — wszystkie ich route'y zweryfikowane jako istniejące w `routes/crafter.php`.)

---

## 7. Jak działa nowy sidebar

- **Top-level grupy** (`SidebarGroup`): UPPERCASE, ikona Heroicons, chevron rotujący, ring na aktywnej.
- **Auto-rozwijanie:** wejście na podstronę automatycznie rozwija grupę zawierającą aktywny link (hook `useSidebarActive`, propagacja w górę).
- **Jasna stopka:** avatar + imię/nazwisko + dropdown (granatowy tekst).
- **Animacje:** `v-auto-animate` (rozwijanie), chevron `transition-transform`, szerokość `transition-all`.

### Zwijanie lewej kolumny (collapsed mode)
- Przycisk **`«` / `»`** w górnym pasku (tylko desktop).
- Klik → sidebar zwija się do **64px (same ikony)**, content się rozszerza.
- Stan **zapamiętany** w `localStorage` (`crafter.sidebarCollapsed`).
- Zwinięty + klik w ikonę grupy → rozwija cały sidebar i otwiera tę grupę.
- Ikony mają **tooltipy** (`title`) po najechaniu.
- Mobile bez zmian (overlay dialog, pełna szerokość).

---

## 8. Świadomie pominięte (TODO na przyszłość)

- ❌ **Theme Settings** (Spatie): `app/Settings/ThemeSettings.php`, migracja `theme_settings`, `Pages/Settings/ThemeTab.vue`, share `theme` w `HandleInertiaRequests.php`, rejestracja w `config/settings.php`. Backend nieskonfigurowany.
- ❌ **Dynamiczna szerokość z panelu** (`--sidebar-width` CSS var, slider 180–400px) — zależy od Theme Settings.
- ❌ **Pełne menu z dokumentu** (sekcje Argo PIM/Scope/Scale/Agenci/Admin z 537-linijkowego wzorca) — część route'ów (`scope.*`, `ai-agents.*`, `mail.*`, `backup.*`, `user-groups.*`) **nie istnieje** w `routes/crafter.php`; wklejenie wywaliłoby sidebar (Ziggy „route not found"). Dodano tylko sekcje z istniejącymi route'ami.

---

## 9. Build i weryfikacja

```bash
cd "/d/laragon/www/PIM"
export PATH="/c/laragon/bin/nodejs/node-v18:$PATH"
npm run build
```

Po buildzie w przeglądarce **Ctrl+Shift+R** (twardy reload — cache SPA/Inertia).

Weryfikacja serwera (że serwuje nowy build):

```bash
# HTML linkuje nowy bundle:
curl -s -k http://pim.test/admin/login | grep -oE 'index-[a-z0-9]+\.js'

# CSS zawiera granat:
curl -s -k "http://pim.test/build/assets/crafter-<hash>.css" | grep -o "15275a" | wc -l

# JS zawiera logikę zwijania:
grep -l "sidebarCollapsed" public/build/assets/*.js
```

Stan na koniec sesji: build OK (`✓ built in ~18s`), serwer linkuje `index-620e643f.js` zawierający `sidebarCollapsed` + zapis do `localStorage`, CSS `crafter-b26ad97d.css` z granatem.

---

## 10. Lista wszystkich dotkniętych plików

**Utworzone:**
```
resources/js/crafter/hooks/useSidebarActive.ts
resources/js/crafter/Components/Sidebar/SidebarSubGroup.vue
resources/js/crafter/Components/Sidebar/SidebarSubGroupNavLink.vue
SIDEBAR-REDESIGN-WDROZENIE.md   (ten plik)
```

**Zmienione:**
```
tailwind.config.js
resources/js/crafter/Layouts/Authenticated.vue
resources/js/crafter/Components/index.ts
resources/js/crafter/Components/Sidebar/Sidebar.vue
resources/js/crafter/Components/Sidebar/SidebarGroup.vue
resources/js/crafter/Components/Sidebar/SidebarItem.vue
resources/js/crafter/Components/Sidebar.vue
```

---

## 11. Reorganizacja — grupa ARGO PIM (2026-05-20)

Luźne pozycje PIM rozsiane na górze sidebara zebrane pod jedną grupą **Argo PIM** (`CubeIcon`), wg wzorca z `+importy/argohq-package/`. Przed dodaniem **sprawdzono w `routes/crafter.php`**, które route'y faktycznie istnieją.

**Weryfikacja route'ów:**

| Pozycja | Route | Status |
|---|---|---|
| Integrations | `crafter.integrations.index` | ✅ |
| Status sync | `crafter.integrations.status` | ✅ |
| Products | `crafter.products.index` | ✅ |
| Categories | `crafter.categories.index` | ✅ |
| Pricelists | `crafter.pricelists.index` | ✅ |
| Sources | `crafter.sources.index` | ✅ |
| Attributes | `crafter.attributes.index` | ✅ |
| Templates | `crafter.templates.index` | ✅ |
| Media | `crafter.media.index` | ✅ |
| Zestawy | `crafter.sets.index` | ❌ brak — pominięte |
| Grupy atrybutów | `crafter.attribute-groups.index` | ❌ brak — pominięte |
| Blog (Blogi/Kategorie/Artykuły/Autorzy) | `crafter.blog*.index` | ❌ brak — pominięte |

**Struktura Argo PIM (tylko istniejące route'y):**
```
Argo PIM (CubeIcon)
├── Integracje (SubGroup) [v-can crafter.integration.index]
│   ├── Integrations
│   └── Status sync
├── Oferta (SubGroup)
│   ├── Products
│   ├── Categories
│   ├── Pricelists
│   └── Sources
├── Opcje (SubGroup)
│   └── Attributes
├── Szablony
└── Media
```

**Nowy układ menu (kolejność):** Dashboard → Argo HQ → **Argo PIM** → Argo Connect → Argo Task → AI Tools → Users → (System, ukryty).

**Pozostałe zmiany w `Components/Sidebar.vue`:**
- Użyto komponentu `SidebarSubGroup` (dodany import) dla zagnieżdżeń w Argo PIM.
- Usunięto nieużywane już importy ikon (`PhotoIcon`, `CurrencyDollarIcon`, `PuzzlePieceIcon`, `CubeTransparentIcon`, `ShoppingCartIcon`, `AdjustmentsHorizontalIcon`, `CircleStackIcon`, `QueueListIcon`), dodano `CubeIcon`.
- AI Tools i Users zostawione jako luźne pozycje top-level (ich docelowe sekcje z dokumentu — Argo Agenci / Admin — mają route'y, których brak w tym projekcie).
