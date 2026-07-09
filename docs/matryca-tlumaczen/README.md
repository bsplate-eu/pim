# Matryca tłumaczeń produktów — dokumentacja

> System automatycznego tłumaczenia nazw produktów w oparciu o **matrycę fraz** (PL → 6 lokali + 5 kont Allegro),
> z ochroną ręcznych tłumaczeń przed nadpisywaniem przez synchronizację Sumpguard.
>
> Data wdrożenia: 2026-06 · Autor zmian: zespół PIM

---

## Po co to jest (problem biznesowy)

Sumpguard (źródło danych) zaciąga nazwę produktu i wpisuje ją do **wszystkich wersji językowych naraz**.
Ręczne poprawki tłumaczeń w PIM **znikały po każdej nocnej synchronizacji** (cron 01:00), bo
`SumpguardSource` nadpisywał całą kolumnę `name` (JSON Spatie) świeżym fallbackiem z feedu.

Dodatkowo na sklepach językowych (DE/FR/...) lądował **polski tekst** — bo Sumpguard nie ma realnych
tłumaczeń i podstawiał polski do każdego slotu.

**Rozwiązanie:** lokalna **matryca fraz** zbudowana z ~1500 ręcznie przetłumaczonych produktów +
mechanizm **ochrony** (lock per pole+locale) + **sklejacz** który automatycznie tłumaczy nowe produkty.

---

## Spis treści

| Plik | Zawartość |
|---|---|
| [01-architektura.md](01-architektura.md) | Tabele DB, modele, przepływ danych, kanały |
| [02-komendy.md](02-komendy.md) | Wszystkie komendy artisan z przykładami |
| [03-ochrona-i-observers.md](03-ochrona-i-observers.md) | Jak działa lock, observerzy, suppress |
| [04-sklejacz.md](04-sklejacz.md) | ProductTranslationComposer — jak fraza → nazwa |
| [05-ui.md](05-ui.md) | Strony crafter: matryca + review queue |
| [06-naprawa-kodowania.md](06-naprawa-kodowania.md) | Problem „Os??ona" (popsute polskie znaki) i fix |
| [07-rollout.md](07-rollout.md) | Kolejność uruchamiania na produkcji + checklist (v1) |
| **[08-v2-klasyfikator.md](08-v2-klasyfikator.md)** | **🔥 WERSJA 2: klasyfikator kanoniczny (537→33 frazy), deriver, composer v2, prostowanie PL** |
| **[09-review-queue-ui.md](09-review-queue-ui.md)** | **Review queue: wyszukiwarka, sortowanie, operacje masowe, auto-approve** |
| **[10-rollout-produkcja.md](10-rollout-produkcja.md)** | **Rollout 2026-06-10 + pułapki (parse error, brak git/composer, Google Sheets)** |
| **[11-incydent-nazwy-2026-07-02.md](11-incydent-nazwy-2026-07-02.md)** | **🚑 Post-mortem: nadpisanie nazw na prodzie + żelazne zasady masowych operacji na `products.name`** |

> ⚠️ **Pliki 01-07 opisują wersję 1 (stripSuffix).** Rdzeń został przebudowany na **klasyfikator kanoniczny**
> — aktualny opis w **[08-v2-klasyfikator.md](08-v2-klasyfikator.md)**. Stary `stripSuffix` i `BRAND_ALIASES` usunięte.

> 🚑 **Incydent + recepta odbudowy (gdy obce nazwy nagle = PL):** [../2026-06-26-integracje-grid-i-incydent-tlumaczen.md](../2026-06-26-integracje-grid-i-incydent-tlumaczen.md).
> Przyczyna: sync Sumpguard wpisał PL do wszystkich lokali, bo na `de/cs/sk/fr/es` **brakowało locków** (cron ~01:00). Matryca przeżywa → odbudowa = skasuj locki `auto_matrix`/`sheet_import` (zachowaj `manual`) → `translations:auto-translate`. **Nigdy nie zostawiaj skasowanych locków `name` przez noc.**

---

## Stan aktualny (v2, środowisko lokalne, 2026-06-10)

| Tabela | v1 (było) | v2 (jest) |
|---|---|---|
| `translation_phrases` (frazy = typy produktów) | 537 (88% śmieci) | **33 kanoniczne** |
| `translation_phrase_renditions` (tłumaczenia per kanał) | 5404 | **~363 czyste** (0 śmieci) |
| Produkty z kompletem 6/6 | — | **1490/1491** |
| `translation_overrides` (chronione sloty) | ~14 479 | bez zmian |

Klasyfikator rozpoznaje **100%** katalogu. Szczegóły: **[08-v2-klasyfikator.md](08-v2-klasyfikator.md)**.

---

## Kluczowa zasada: kanały

Matryca operuje na **11 kanałach** (nie tylko 6 locale):

```
pl, de, cs, sk, fr, es                          ← products.name (Spatie translatable)
allegro_klapypodsilnik  (integracja 13)         ┐
allegro_czescipareto    (integracja 14)         │
allegro_dolneoslony     (integracja 16)         ├─ integration_products.overrides.name
allegro_ksteileshop     (integracja 17)         │   (per konto Allegro / Baselinker)
allegro_oslonypareto    (integracja 18)         ┘
```

Plus alias: integracja **12** (`oslonypareto_pl`) = kopia kanału `allegro_oslonypareto`.

**Allegro NIE jest osobnym locale** (`pl2/pl3` itd.) — to per-integracja `overrides.name`,
bo każde konto Allegro = osobny rekord w tabeli `integrations`.

---

## Najważniejsze pliki w kodzie

| Warstwa | Plik |
|---|---|
| Migracje | `database/migrations/2026_05_28_1000*_*.php` |
| Modele | `app/Models/TranslationPhrase.php`, `TranslationPhraseRendition.php`, `TranslationOverride.php` |
| Sklejacz | `app/Services/ProductTranslationComposer.php` |
| Observerzy | `app/Observers/TranslationTrackingObserver.php`, `IntegrationProductTrackingObserver.php` |
| Źródło (zmiany) | `app/Sources/SumpguardSource.php` |
| Komendy | `app/Console/Commands/Translations*.php` |
| Kontrolery | `app/Http/Controllers/Admin/TranslationPhraseController.php`, `TranslationReviewController.php` |
| UI | `resources/js/crafter/Pages/TranslationPhrase/*.vue`, `TranslationReview/Index.vue` |
| Routy | `routes/crafter.php` (sekcja „Translation matrix") |
| Rejestracja observerów | `app/Providers/AppServiceProvider.php` |
