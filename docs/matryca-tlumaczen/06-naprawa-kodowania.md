# 06 — Naprawa popsutych polskich znaków (`Os??ona`)

## Objaw
W UI (review queue, listy) nazwy typu **`Stalowa Os??ona pod silnik`** — polskie diakrytyki
zamienione na literalne `?`.

## Diagnoza
Surowe bajty w bazie:
```
"Os??ona"  →  hex: 4f 73 3f 3f 6f 6e 61   (3f = literalny '?')
```
„ł" (UTF-8 = `c5 82`, 2 bajty) został zamieniony na **dwa znaki `?`**. To **lossy** —
oryginalna litera jest BEZPOWROTNIE utracona w bazie (nie da się odzyskać samą konwersją charsetu).

### Przyczyna
Historyczny import Sumpguarda zapisał dane przez połączenie ze **złym charsetem** (np. latin1/ascii),
które nie potrafiło zakodować polskich liter → każdy non-ASCII bajt stał się `?`.

**Aktualne połączenie działa poprawnie** — dowód: import z arkusza (`translations:import-from-sheet`)
zapisał czyste UTF-8. Czyli problem jest tylko w STARYCH danych, nie w bieżącej konfiguracji.

### Źródło prawdy do naprawy
Feedy `storage/app/sumpguard/{locale}.json` są **czystym UTF-8**:
```
feed "Osłona" → hex: 4f 73 c5 82 6f 6e 61   ✅ poprawne ł
```

## Skala (środowisko lokalne)
| Obszar | Uszkodzonych |
|---|---|
| `products.name` locale matrycy (pl/de/cs/sk/fr/es) | ~358 produktów, 1567 slotów |
| `products.name` locale SPOZA matrycy (en/lt/it/lv/et/ro/hu/bg) | ~1482 każdy ≈ 11 865 slotów |
| `attribute_values.name` | 77 |
| `categories.name` | 0 |
| `integration_products.overrides` (głównie STARE wyłączone integracje 2/5/7…) | ~8017 |

> Integracje Allegro w zakresie (13/14/16/17/18) były czyste — naprawił je import z arkusza.

## Naprawa

### Krok 1 — locale matrycy (z feedu)
```bash
php artisan translations:repair-encoding --dry-run
php artisan translations:repair-encoding
```
Dla każdego produktu z `?` w slocie: bierze czystą nazwę z `feed[locale][external_id]`,
**pomija sloty zablokowane** (manual/sheet_import/auto_matrix), suppress observer.
Wynik: 1567 slotów naprawionych, zostaje tylko to czego nie ma w feedzie (produkty usunięte z Sumpguard).

### Krok 2 — locale spoza matrycy (czyszczenie)
Te 8 lokali (en/lt/it/lv/et/ro/hu/bg) to języki **nietłumaczone** — trzymały tylko popsuty
polski fallback. **Decyzja: wyczyścić** (puste sloty = „brak tłumaczenia", zgodnie z filozofią matrycy).
```bash
php artisan translations:repair-encoding --clear-non-matrix --dry-run
php artisan translations:repair-encoding --clear-non-matrix
```

### Krok 3 — produkty usunięte z feedu (ręcznie)
Produkty których nie ma już w feedzie Sumpguard (np. external_id 2001) — naprawić ręcznie w UI
lub tinkerem. Bywają „śmietnikiem" (różne produkty w różnych slotach) → najlepiej wyczyścić
błędne sloty i puścić sklejacz.

## Stan po naprawie
- `products.name` (pl/de/cs/sk/fr/es): **czyste** (poza nielicznymi spoza feedu)
- locale spoza matrycy: **wyczyszczone** (puste, gotowe na przyszłą matrycę)

## Pozostałe do rozważenia (osobno)
- `attribute_values.name` (77) — wartości `protection`/`oil`/`engine` z `?`. Nie wpływają na sklejanie
  nazw (sklejacz używa tylko make+model, które są ASCII), ale widać je w karcie produktu.
  Do naprawy z feedu (pola atrybutów) lub ręcznie.
- `integration_products.overrides` starych WYŁĄCZONYCH integracji (2/5/7…) — poza zakresem
  (te integracje `enabled=0`, nie eksportują).
