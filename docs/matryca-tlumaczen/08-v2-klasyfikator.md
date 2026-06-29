# 08 — Wersja 2: klasyfikator kanoniczny (zamiast stripSuffix)

> Data: 2026-06-09/10. Przebudowa rdzenia: z **537 śmieciowych fraz** do **33 kanonicznych**.
> Powód: stary `stripSuffix` (odcinanie marki/modelu z nazwy) zawodził przy `A4 B9`, `Vauxhall Vivaro`,
> `4x4`, `diesel` → 88% fraz to były singletony z marką w slugu, bez tłumaczeń.

---

## Idea: rozpoznawać typ, nie odcinać markę

Stary model (v1, [04-sklejacz.md](04-sklejacz.md)): `nazwa PL − make − model → prefix → slug`. Kruche.

Nowy model (v2): **klasyfikator regułowy** rozpoznaje typ produktu z samych słów technicznych.
Katalog jest wąski (osłony podwozia), więc każdy produkt = 3 wymiary:

```
{Materiał} osłona {element} {wykończenie}
   │              │            │
   Stalowa        silnika      (puste)
   Aluminiowa     skrzyni biegów  galwanizowana / z Webasto / System Start-Stop
```

Szyk frazy: **`{Materiał} osłona {element} {wykończenie}`** — np. `Stalowa osłona silnika galwanizowana`.

Cały katalog (~1500 produktów) sprowadza się do **33 fraz**, rozpoznanie **100%**.

---

## `ProductPhraseClassifier` (serwis)

Plik: `app/Services/ProductPhraseClassifier.php`

```php
classify(?string $plName): ?array  // [material, element, modifiers, phrase_pl, slug] lub null
```

- **Materiał:** `/alumini/` → Aluminiowa, inaczej Stalowa.
- **Element:** `ELEMENT_RULES` — lista [regex, label], kolejność = priorytet (specyficzne przed generycznymi).
  silnika / skrzyni biegów / dyferencjału / zbiornika paliwa / AdBlue / katalizatora / chłodnicy / reduktora /
  DPF / EGR / skrzynki transferowej / filtra paliwa / przedniego zderzaka / akumulatora / czujnika tylnego wahacza /
  + złożone: „silnika i skrzyni biegów", „skrzyni biegów i reduktora". `silnika` jest OSTATNIE (najszersze).
- **Wykończenia (`MODIFIER_RULES`)** doklejane na końcu: galwanizowana / z Webasto / System Start-Stop.
- **`html_entity_decode` na wejściu** — feed bywa z `&amp;` (np. „Stop&amp;Go").
- `null` = element nierozpoznany → produkt do review (jedyny przypadek wymagający człowieka).

Klasyfikator **NIE używa marek ani modeli** — to eliminuje cały dług `BRAND_ALIASES` (był zduplikowany w 3 plikach).

---

## `PhraseRenditionDeriver` (serwis) — generowanie tłumaczeń wariantów

Plik: `app/Services/PhraseRenditionDeriver.php`

Nowa fraza-wariant powstaje z BAZY przez deterministyczną transformację:

| Typ | Z czego | Jak |
|---|---|---|
| Materiał | „Aluminiowa osłona X" ← „Stalowa osłona X" | podmiana słowa materiału per kanał (`MATERIAL_MAP`: Stahl→Aluminium, Ocelový→Hliníkový, Acier→Aluminium, metalico→de aluminio...) |
| Modyfikator | „...z Webasto" ← „..." | doklejenie przetłumaczonego sufiksu (`SUFFIX_TRANSLATIONS`: de „mit Webasto", cs „s Webasto"...) |
| Kombinacja | „...i skrzyni biegów" ← „..." | doklejenie (de „und Getriebe", cs „a převodovky"...) |

- `deriveFor($phrase)` — generuje renditcje pochodnej, zwraca ile zapisał (0 = brak bazy / nowy element).
- `deriveAll()` — pętla do-while aż nic nie powstanie (rozwiązuje łańcuch: stal silnik → alu silnik → alu silnik z Webasto).
- **Idempotentny**: modyfikatory są usuwane z ogona bazy przed doklejeniem, więc 1× czy 10× = ten sam wynik.

**Wystarczy że istnieje baza** (np. „Stalowa osłona silnika" z arkusza) — wszystkie warianty system generuje SAM.
Nowy ELEMENT (nieznany rzeczownik bez bazy) → 0 → review (jedyny przypadek dla człowieka, bez AI).

---

## Composer v2 — klasyfikator + prostowanie PL

Plik: `app/Services/ProductTranslationComposer.php` (przepisany)

Zmiany względem v1:
1. **`compose()` używa klasyfikatora** zamiast `stripSuffix` (usunięty wraz z `BRAND_ALIASES`).
2. **`apply()` jest samowystarczalny** — `ensurePhrase()` na początku: classify → `firstOrCreate` fraza → `deriveFor` jeśli pusta.
   Dzięki temu hook w `SumpguardSource` (woła `apply`) automatycznie ogarnia nowe warianty — **bez zmian w Sumpguard**.
3. **Prostowanie PL** (`straightenPl`) — patrz niżej.
4. **`WRITABLE_LOCALE_CHANNELS`** = `de/cs/sk/fr/es` (bez `pl` w starym sensie — PL prostowany osobno).

### Prostowanie PL (`straightenPl`)

Feed dawał śmieciowy PL: `Stalowa Osłona pod silnik Citroen Grand C4 SpaceTourer Aluminium`
→ prostowanie → `Aluminiowa osłona silnika Citroen Grand C4 SpaceTourer`.

Mechanizm: **czysty typ z klasyfikatora + ogon oryginału OD KOŃCA ELEMENTU** (kotwica `silnik`/`bieg`/`dyferencj`...).

- Ogon liczony od końca elementu (NIE od marki) → zachowuje warianty PRZED marką (`manualnej`, `automatycznej`) i PO niej (`4x4`, `Diesel`, `Life`, `pakiet promocyjny`).
- Usuwa wiszący materiał z ogona (`aluminium` gdziekolwiek) i zdublowane modyfikatory.
- **Dedup marki**: `Mercedes Mercedes V-Class` → `Mercedes V-Class`.
- **STRAŻNIK**: jeśli wynik zgubiłby markę i model (zła kotwica / błędna klasyfikacja) → zwraca oryginał nietknięty.
- Chroni `manual` lock; nadpisuje tylko nie-ręczne PL.

> ⚠️ Pułapka której uniknięto: pierwsza wersja brała ogon „od marki" → gubiła `manualnej` (przed marką).
> Druga pułapka: modyfikator był w typie I w ogonie → potrojenie „z Webasto z Webasto z Webasto". Fix: usuń modyfikator z ogona + idempotencja.

---

## Komenda `translations:reclassify`

Plik: `app/Console/Commands/TranslationsReclassify.php`

```bash
php artisan translations:reclassify              # podgląd planu (zero zapisu)
php artisan translations:reclassify --apply      # przebuduj matrycę
php artisan translations:reclassify --apply --prune   # + usuń stare frazy-śmieci
```

- Klasyfikuje wszystkie produkty → distinct frazy + product_count.
- Frazy które ocalają slug (np. `stalowa_oslona_silnika`) **zachowują renditcje**; nowe powstają puste.
- `--prune` usuwa stare frazy spoza nowego zbioru.
- Po przebudowie odpala **`deriveAll()`** (auto-derive wariantów).

---

## Audyt jakości (co naprawiono po drodze)

Stara matryca (głosowanie top-vote z arkusza) miała śmieci w rzadkich komórkach:

| Klasa błędu | Skala | Naprawa |
|---|---|---|
| Stare renditcje aluminiowe trefne (es=skrzynia, fr=Acier/stal, „xx") | ~12 fraz | derive z czystych stalowych |
| „Cudzy element" — arkusz miał przesunięte wiersze sk/fr/es (silnik↔skrzynia↔zbiornik) | ~790 slotów produktów | przeliczenie z naprawionej matrycy |
| Stal na produktach aluminiowych | 221 slotów | composer + reset auto_matrix |
| Polski w obcych slotach (relikt „kopiuj PL wszędzie") | 10 | jw. |
| Tłumaczenie innego produktu (Maxus T60 na VW Crafter) | 1 | jw. |

**Stan końcowy:** 33 frazy, ~363 renditcje, **0 śmieci**, **1490/1491 produktów z kompletem 6/6** (1 egzotyczny czujnik wahacza).

Backupy: `storage/app/translations/backup_matryca_2026-06-09.json`, `backup_pl_names_2026-06-10.json`.

---

## Najważniejsze pliki (v2)

| Warstwa | Plik |
|---|---|
| Klasyfikator | `app/Services/ProductPhraseClassifier.php` |
| Deriver wariantów | `app/Services/PhraseRenditionDeriver.php` |
| Composer (przepisany) | `app/Services/ProductTranslationComposer.php` |
| Przebudowa matrycy | `app/Console/Commands/TranslationsReclassify.php` |
| Auto-zatwierdzanie | `app/Console/Commands/TranslationsAutoApprove.php` ([09](09-review-queue-ui.md)) |
