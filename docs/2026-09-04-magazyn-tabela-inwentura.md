# Magazyn → Tabela: import arkusza inwentury

Ekran `admin/production/magazyn/tabela` pokazuje arkusz inwentury 1:1 — w tym
samym układzie kolumn, w jakim prowadzą go ludzie. To świadomie **nie jest**
znormalizowana tabela stanów: kto pracuje na tym arkuszu, musi odnaleźć na
ekranie dokładnie to, co ma u siebie, razem z pustymi kolumnami i własnymi
skrótami miejsc.

Źródło: `Magazyn PARETO.xlsx`, zakładka **`2026 - inwentura`** (nazwa ma spacje
wokół myślnika). Plik przychodzi ręcznie, nie przez Google API.

## Import

```bash
php artisan warehouse:import-sheet "sciezka/Magazyn PARETO.xlsx"
php artisan warehouse:import-sheet plik.xlsx --tab="2027 - inwentura"   # inna zakładka
php artisan warehouse:import-sheet plik.xlsx --dry-run                  # policz, nie zapisuj
```

Import jest **podmianą całej zakładki** w transakcji, nie doklejaniem: arkusz
jest źródłem prawdy, więc kod, który z niego zniknął, znika i w bazie.

Stan po pierwszym imporcie (04.09.2026): 615 kodów, 1 597 szt., 120 kodów ze
stanem 0, 116 w dwóch lub więcej miejscach, 8 z uwagami, **43 bez pary w PIM**.

## Układ kolumn i pułapki arkusza

Układ jest wpisany na sztywno w `ImportWarehouseSheet::PAIRS`, bo nagłówki są
rozjechane i czytanie ich zrobiłoby więcej szkody niż pożytku:

| kolumny | znaczenie |
|---|---|
| A | kod |
| B/C, D/E, F/G, H/I | cztery pary Miejsce/il. (F podpisane „mondeło !", to zwykłe Miejsce) |
| M/N, O/P | **piąta i szósta para** — bez nagłówków, korzysta z nich jeden wiersz (567, kod `98.041`) |
| J | podpisane „il.", ale puste w całym arkuszu |
| K, L | uwagi (K nosi nagłówek „steel team") |
| P, R | nagłówki WYMIAR i WAGA |

Cztery decyzje podjęte przy imporcie:

- **NULL ≠ 0.** Puste pole arkusza to `null` („nikt nie liczył"), jawne zero to
  `0` („policzone, nie ma"). Na ekranie pierwsze jest kreską, drugie szarym
  zerem. W arkuszu są 120 jawne zera i one coś znaczą.
- **WYMIAR nie jest importowany.** Kolumna P nosi ten nagłówek, ale trzyma ilość
  szóstej pary (wiersz 567). Kolumny `wymiar` i `waga` istnieją w bazie i na
  ekranie — puste, czekają na uzupełnienie.
- **Samotny przecinek to nie treść.** `,` w kolumnie L (3 wiersze) leci jako
  puste.
- **Komórka spoza siatki.** `AD61 = "tak"` trafia do uwag kodu `00.1792` jako
  `[kol. AD: tak]`, żeby nie zginęła.

Wiersz `suma:` na końcu arkusza jest pomijany — to podsumowanie, nie kod.

## Dopasowanie do PIM

`in_pim` liczy kontroler, nie baza: kod z arkusza bywa zapisany inaczej niż
`products.product_code`. Porównanie idzie po znormalizowanym kodzie (trim,
wielkie litery, bez spacji), co zdejmuje rozjazdy typu `25.159 ALU` vs
`25.159ALU` — pięć kodów wchodzi tą drogą i nie są realnym brakiem produktu.

Zostają **43 kody bez pary**, z czego 20 ma stan niezerowy — m.in. `08.502-1`
(4 szt.), `26.174B` (4), `30.006B` (4), `27.203` (3), `21.002`, `21.003`,
`17.114INOX`, `moto-hak`, `płyta-wąska`, `płyta-szeroka`. To zawartość kubełka
„Do zmapowania", a nie lista błędów.

## Wdrożenie na produkcję

Migracja jedzie z repo, ale **dane nie** — plik XLSX trzeba wgrać osobno:

```bash
php artisan migrate --force
# XLSX z lokalnej maszyny:
# scp -i D:/laragon/www/SSH/bsp-auto "Magazyn PARETO.xlsx" admin@5.196.81.23:~/
php artisan warehouse:import-sheet ~/"Magazyn PARETO.xlsx"
```

Data importu na ekranie jest w UTC — `config/app.php` ustawia `timezone` na UTC
dla całego PIM, więc pokazuje się dwie godziny wstecz względem zegara w Polsce.
