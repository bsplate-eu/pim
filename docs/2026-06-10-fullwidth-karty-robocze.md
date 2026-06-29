# Karty robocze full-width (100% obszaru roboczego)

**Data:** 2026-06-10
**Status:** wdrożone lokalnie, build przeszedł (`✓ built in 20.73s`). Zmiana wyłącznie frontowa (klasy Tailwind w plikach `.vue`) — zero zmian w PHP, trasach i bazie. Na prod wystarczy wgrać świeży `public/build` przy najbliższym deployu.

## Cel

Każda karta robocza (główna zawartość strony) ma zajmować **100% szerokości obszaru roboczego** na każdej stronie panelu. Dotychczas wiele stron (m.in. *Edit Pricelist*) renderowało wąską, wycentrowaną kartę z szerokimi pustymi marginesami po bokach — na monitorach 1920px+ marnowało to większość ekranu, szczególnie przy dużych tabelach (cenniki ~1500 wierszy).

## Diagnoza

- Layout **`PageContent.vue` był już domyślnie fluid** (`fluid: true`, bez `max-w-screen-2xl`) — globalny kontener nie był winowajcą.
- Nikt nie używał `:fluid="false"`.
- Komponenty `Card` i `PageHeader` — bez ograniczeń szerokości (sprawdzone).
- Zwężenie powodowały **per-stronowe wrappery `max-w-*` + `mx-auto`** w plikach `resources/js/crafter/Pages/**`.

## Zmiany (22 wrappery w 21 plikach)

Wszystkie poniżej w `resources/js/crafter/Pages/`:

| Plik | Przed | Po |
|---|---|---|
| `Pricelist/Form.vue` | `mx-auto max-w-3xl 2xl:max-w-7xl` | `w-full` |
| `AiTool/Form.vue` | `mx-auto max-w-3xl 2xl:max-w-4xl` | `w-full` |
| `AdminUser/Profile/Edit.vue` | `mx-auto max-w-3xl 2xl:max-w-4xl` | `w-full` |
| `AdminUser/Password/Edit.vue` | `mx-auto flex max-w-3xl flex-col gap-6 2xl:max-w-4xl` | `flex w-full flex-col gap-6` |
| `AdminUser/Form.vue` | `mx-auto flex max-w-3xl flex-col gap-6 2xl:max-w-4xl` | `flex w-full flex-col gap-6` |
| `AttributeValue/Form.vue` | `mx-auto max-w-3xl 2xl:max-w-4xl` | `w-full` |
| `Attribute/Form.vue` | `mx-auto max-w-3xl 2xl:max-w-4xl` | `w-full` |
| `Category/Form.vue` | `mx-auto max-w-3xl 2xl:max-w-4xl` | `w-full` |
| `Source/Form.vue` | `mx-auto max-w-3xl 2xl:max-w-4xl` | `w-full` |
| `Product/Export.vue` | `mx-auto max-w-3xl 2xl:max-w-4xl` | `w-full` |
| `Integration/Form.vue` | `mx-auto max-w-8xl` | `w-full` |
| `Settings/Index.vue` | `… max-w-screen-lg mx-auto` | `… w-full` |
| `Mail/TemplateEdit.vue` | `p-4 space-y-6 max-w-4xl` | `p-4 space-y-6 w-full` |
| `Mail/Smtp.vue` | `p-4 space-y-6 max-w-3xl` | `p-4 space-y-6 w-full` |
| `TranslationPhrase/Edit.vue` | form: `space-y-6 max-w-4xl` | `space-y-6 w-full` |
| `ArgoMail/Accounts/Form.vue` | form: `space-y-6 max-w-3xl` | `space-y-6 w-full` |
| `ArgoMail/Settings.vue` | 6× `max-w-3xl` (zakładki: katalogi, kategorie, użytkownicy, filtry, spam, bez grupowania) | 6× `w-full` |
| `ArgoTask/TaskShow.vue` | `max-w-4xl mx-auto py-6` | `w-full py-6` |
| `ArgoTask/CreateProject.vue` | `max-w-2xl mx-auto` | `w-full` |
| `ArgoTask/EditGroup.vue` | `max-w-2xl mx-auto` | `w-full` |
| `ArgoTask/CreateGroup.vue` | `max-w-2xl mx-auto` | `w-full` |
| `Home.vue` (dashboard) | `mx-auto mt-6 w-full max-w-screen-2xl px-…` | `mt-6 w-full px-…` |

## Celowo NIE ruszone (wąska szerokość zamierzona)

- **Modale:** `Integration/Status.vue` (max-w-2xl), `Product/AiToolsModal.vue` (max-w-3xl), popupy w `ArgoMail/Index.vue` (max-w-md).
- **Panele boczne / kolumny:** slide-over w `Connect/Map/Index.vue`, lista maili w `ArgoMail/Index.vue` (`lg:max-w-sm`).
- **Elementy w tabelach:** komórki z `truncate`/`max-w-sm` (`Translations/Index.vue`, `Connect/Orders/Index.vue`, `Media/Index.vue`).
- **Teksty pustych stanów** (`max-w-md` na `<p>`).
- **Ekran logowania** (`Layouts/Guest.vue`).

## Zasada na przyszłość

Nowe strony w `resources/js/crafter/Pages/**`: **nie dodawać `max-w-*` ani `mx-auto` na wrapperze głównej zawartości** — `PageContent` jest domyślnie fluid, wystarczy zwykły `div` / `w-full`. Jeśli kiedyś konkretna strona ma być zwężona, służy do tego prop `:fluid="false"` na `PageContent` (obecnie nieużywany), a nie ręczne `max-w-*`.

## Build / wdrożenie

- Build lokalny: `npm run build` (Node 18 z `C:\laragon\bin\nodejs\node-v18` — npm nie jest w PATH).
- Wynik: `✓ built in 20.73s`; jedyne ostrzeżenie to istniejące wcześniej „chunks larger than 500 kB".
- **Prod:** brak npm/node na serwerze — wgrać lokalnie zbudowany `public/build` (pamiętając o symlinku `public_html/build → PIM/public/build`); procedura w [`deploy.md`](../deploy.md).
