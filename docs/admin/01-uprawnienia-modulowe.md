# 01 — Uprawnienia modułowe (moduły Argo)

## Problem

Moduły dobudowane po Crafterze nie mają klas Request z bramką `Gate`. Dopisanie uprawnień do każdego z ~50 kontrolerów z osobna byłoby długie i łatwo o pominięcie jednej metody. Zamiast tego uprawnienie przypisane jest **prefiksowi nazwy trasy**, a pilnuje tego jedno miejsce.

## 9 uprawnień

| uprawnienie | obejmuje |
|---|---|
| `crafter.module.costs` | Argo HQ → Koszty: planer, zestawienia, raporty, wyciągi bankowe, odyssey‑cost |
| `crafter.module.kasa` | Argo HQ → Kasa |
| `crafter.module.ksef` | Argo HQ → KSeF (Pareto/BSP) + Connect → Integracje · KSeF |
| `crafter.module.connect` | Argo Connect: zamówienia, klienci, mapa, integracje Base, chatbot |
| `crafter.module.marketplace` | Connect → Marketplace eBay: aukcje, kategorie, schematy, wystawianie |
| `crafter.module.scope` | Argo Scope (scrapy konkurencji) |
| `crafter.module.task` | Argo Task (grupy, projekty, zadania) + mobilny widok zadań |
| `crafter.module.mail` | Argo Mail (skrzynka, konta, administrator AI) + mobilna poczta |
| `crafter.module.translations` | Tłumaczenia: matryca, review, logi, ustawienia |

Zakłada je migracja `database/migrations/2026_08_19_100000_add_module_permissions.php`, która **nadaje komplet wszystkim istniejącym rolom poza „Guest"** — dzięki temu w dniu wdrożenia nikt nie traci dostępu. Zawężanie robi się świadomie w macierzy.

## Mapa tras

`config/module-permissions.php`:

```php
'map' => [
    'cost-planner.'              => 'crafter.module.costs',
    'bank-statements.'           => 'crafter.module.costs',
    'odyssey-cost.'              => 'crafter.module.costs',
    'kasa.'                      => 'crafter.module.kasa',
    'ksef.'                      => 'crafter.module.ksef',
    'connect.'                   => 'crafter.module.connect',
    'connect.integrations.ksef.' => 'crafter.module.ksef',
    'connect.integrations.ebay.' => 'crafter.module.marketplace',
    'connect.marketplace.'       => 'crafter.module.marketplace',
    'scope.'                     => 'crafter.module.scope',
    'argo-task.'                 => 'crafter.module.task',
    'mobile.tasks'               => 'crafter.module.task',
    'argo-mail.'                 => 'crafter.module.mail',
    'ai-tools.mail.'             => 'crafter.module.mail',
    'mobile.mail'                => 'crafter.module.mail',
    'translation-phrases.'       => 'crafter.module.translations',
    'translation-review.'        => 'crafter.module.translations',
    'translation-logs.'          => 'crafter.module.translations',
    'translation-settings.'      => 'crafter.module.translations',
],
```

Klucz to prefiks nazwy trasy **bez** przedrostka `crafter.`.

**Wygrywa najdłuższy pasujący prefiks.** Dlatego `connect.marketplace.` przebija `connect.`, a `connect.integrations.ksef.` przebija oba. Kolejność wpisów nie ma znaczenia.

Sekcja `except` wyłącza spod kontroli endpointy techniczne i „własne" użytkownika: `push.` (subskrypcja powiadomień), `exchange-rates.` (kursy NBP), `mobile.home`, `mobile.notifications`.

## Egzekwowanie

`app/Http/Middleware/EnsureModuleAccess.php`, wpięty na końcu grupy `crafter.base` w `app/Http/Kernel.php` — czyli obejmuje **wszystkie** trasy panelu.

Zasady:

- brak zalogowanego użytkownika → przepuszcza (od tego jest `auth`, a `crafter.base` obsługuje też ekrany logowania),
- trasa bez nazwy lub bez dopasowania w mapie → przepuszcza (stare moduły mają własne bramki),
- dopasowanie + brak uprawnienia → **403** z komunikatem „Brak uprawnień do tego modułu.".

Weryfikacja mapowania na produkcji:

```
crafter.connect.orders.index                    → crafter.module.connect
crafter.connect.marketplace.ebay.listing.index  → crafter.module.marketplace
crafter.ksef.pareto                             → crafter.module.ksef
crafter.products.index                          → przepuszcza (stary moduł)
crafter.dashboard                               → przepuszcza
```

## Dodanie nowego modułu

1. dopisz prefiks trasy → uprawnienie w `config/module-permissions.php`,
2. załóż uprawnienie migracją (wzór: `2026_08_19_100000_add_module_permissions.php`) i nadaj je rolom, które mają zachować dostęp,
3. oznacz pozycję menu w `Sidebar.vue` dyrektywą `v-can` (grupa: `v-can:any="[...]"`),
4. dopisz ludzką etykietę w `app/Support/PermissionLabels.php` (bez niej macierz pokaże surowy klucz).

## Uprawnienie jest zero‑jedynkowe

Nie ma wariantu „tylko podgląd". Kto ma moduł, ten ma w nim wszystko — łącznie z usuwaniem. Przykładowo `crafter.module.connect` otwiera 20 tras, w tym `DELETE /admin/connect/integrations/base/{base}` (skasowanie integracji BaseLinker) i `PUT /admin/connect/chatbot/sales` (zmiana konfiguracji powiadomień). `crafter.module.mail` otwiera 48 tras, w tym wysyłkę maili i usunięcie skrzynki.

Jeśli kiedyś potrzebny będzie podział na odczyt/zapis, naturalna droga to rozbicie uprawnienia na `crafter.module.<moduł>` (odczyt) i `crafter.module.<moduł>.write` z drugim wpisem w mapie dla tras nie‑GET.
