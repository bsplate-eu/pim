# 09 — Review queue: wyszukiwarka, sortowanie, operacje masowe, auto-approve

> Data: 2026-06-10. Rozbudowa `/admin/translation-review` o pełną obsługę masową.

Plik UI: `resources/js/crafter/Pages/TranslationReview/Index.vue`
Kontroler: `app/Http/Controllers/Admin/TranslationReviewController.php`

---

## Licznik pokrycia (poprawka)

**Pokrycie liczy RZECZYWISTE tłumaczenia, nie locki.** Composer v2 celowo nie lockuje PL (to język
źródłowy), więc stary licznik (oparty na lockach) pokazywał 5/6 mimo kompletu. Teraz:

```
pokrycie = PL niepuste + każdy obcy locale (de/cs/sk/fr/es) niepusty i ≠ PL
```

Liczone i w tabeli (per wiersz), i w SQL przy sortowaniu (cały zbiór).

---

## Wyszukiwarka

Pole `?search=` — każde słowo musi wystąpić w PL, DE lub kodzie produktu (AND po słowach, OR po polach).
`COLLATE utf8mb4_unicode_ci` wymagane — `JSON_UNQUOTE` zwraca kolację binarną, bez tego LIKE byłby case-sensitive.
Debounce 350 ms, `preserveState`.

## Sortowanie

Klikalne nagłówki: **ID / PL nazwa / Pokrycie / Status**. `?sort=kolumna` (asc) / `?sort=-kolumna` (desc),
1. klik rosnąco ▲, 2. malejąco ▼. Pokrycie sortowane **w SQL** (wyrażenie CASE per locale), więc działa na
całym zbiorze, nie tylko bieżącej stronie. Przy remisach dokłada `id desc` (stabilna paginacja).

## Zaznaczanie

- **Checkbox w nagłówku** = zaznacz/odznacz całą stronę (klasyczny toggle; stan pośredni `—` gdy część).
- **Baner** „Zaznacz wszystkie N (na wszystkich stronach)" gdy cała strona zaznaczona.
- Tryb „wszystkie pasujące" wysyła `all: true` + bieżący `search` — backend pobiera komplet z bazy.
- Zmiana filtra/sortu/strony czyści zaznaczenie (żeby nie zatwierdzić czegoś spoza widoku).

> ⚠️ Pierwsza wersja miała dropdown przy checkboxie (menu „strona / wszystkie") — mylił. Zastąpiony prostym togglem.

## Operacje masowe

Dwa stałe buttony w **nagłówku** (zawsze widoczne, działają na CAŁĄ kolejkę):
- **Tłumacz wszystkie (N)** → `auto-translate-bulk` (composer->apply na każdym)
- **Zatwierdź wszystkie (N)** → `approve-bulk`

Plus pasek po zaznaczeniu (te same akcje na wybranych).

### Endpointy (routes/crafter.php)
```
POST admin/translation-review/approve-bulk         .approve-bulk
POST admin/translation-review/auto-translate-bulk  .auto-translate-bulk
```
Oba przyjmują `ids: [...]` albo `all: true (+ search)`. Wspólny `reviewQuery($search)` gwarantuje, że
„wszystkie" obejmuje dokładnie to, co widać w kolejce. Przetwarzanie w paczkach po 100-200 (nie ładuje
tysięcy modeli naraz). `approveBulk` → `enabled=true` + `overrides.enabled=1` we wszystkich IntegrationProduct.

---

## Komenda `translations:auto-approve`

Plik: `app/Console/Commands/TranslationsAutoApprove.php`

```bash
php artisan translations:auto-approve              # zatwierdza wszystko z kompletem 5/5 obcych
php artisan translations:auto-approve --dry-run    # podgląd
php artisan translations:auto-approve --min-foreign=4   # luźniej (4 z 5 wystarczy)
```

Zatwierdza (enabled=true + overrides.enabled=1) produkty `enabled=false` z kompletem obcych tłumaczeń.
Idempotentna (bierze tylko enabled=false). Robi to samo co przycisk „Zatwierdź", tylko hurtowo.

### Pełna automatyzacja (opcjonalnie, scheduler)
```php
// app/Console/Kernel.php
$schedule->command('translations:auto-translate --source=SumpguardSource')->dailyAt('01:30');
$schedule->command('translations:auto-approve')->dailyAt('01:45');
```
Łańcuch: **sync → tłumaczenie → zatwierdzenie**, zero klikania. (Świadoma decyzja — produkty z kompletem
wchodzą na sklepy bez ręcznej akceptacji.)

---

## Produkty których automat NIE ruszy (zostają do ręcznego)

Klasyfikator czyta **polski** — produkty z błędnym PL idą do review:
- czeski/obcy tekst w polu PL: `Kryt pod převodovka Evo Cross 4`
- literówki elementu: `Stalowa Osłona reductor ...` (zamiast `reduktor`)
- całkiem nowy element bez bazy w matrycy

Naprawa: popraw PL produktu **albo** wpisz tłumaczenia ręcznie w karcie produktu / dopisz frazę w matrycy.
