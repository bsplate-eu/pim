# Subiekt GT → PIM: stan magazynu M3R

Cztery skrypty na maszynie z Subiektem. Wszystkie **wyłącznie czytają** z SQL
(`ApplicationIntent=ReadOnly` + `READ UNCOMMITTED`) i nie dotykają Sfery — zasada
projektu zostaje: do ERP nie zapisujemy niczym innym niż Sferą, a tutaj nie
zapisujemy w ogóle.

| Plik | Do czego |
|---|---|
| `sprawdz-magazyny.ps1` | rozpoznanie: która baza, jakie są magazyny i ich symbole |
| `wyslij-stan.ps1` | odczyt stanu wskazanego magazynu i wysyłka do PIM |
| `zainstaluj-zadanie.ps1` | to samo co wyżej, ale cyklicznie z Harmonogramu |
| `ping-pim.ps1` | sam ping, do diagnostyki łączności |

## Kolejność

**1. W PIM** — Magazyn → Ustawienia → Argo Bridge: wygeneruj token, wpisz symbol
magazynu, włącz *Połączenie aktywne*.

**2. Token** — obok skryptów utwórz `token.txt` i wklej do niego sam token.

**3. Rozpoznanie bazy i magazynów:**

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\sprawdz-magazyny.ps1
```

Wypisze bazy Subiekta, tabele stanów i tabelę magazynów z `mag_Id`, symbolem
i nazwą, a na końcu ile pozycji leży na każdym magazynie. **Symbol z tej listy**
wpisujemy w PIM — nie numer z nazwy.

> ⚠️ Numer w symbolu nie odpowiada `mag_Id`. M3 potrafi mieć id 2, kolejny id 3,
> w numeracji są dziury. Kto założy, że M3 → 3, ten pobierze stan złego magazynu.

**4. Pierwsza wysyłka ręcznie** (podstaw nazwę bazy z kroku 3):

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\wyslij-stan.ps1 -Database NAZWA_BAZY
```

Oczekiwane: `OK - PIM przyjal N pozycji`. Wejdź do PIM → Magazyn → Magazyn M3R —
kolumna **Stan M3R** ma liczby, a kody, których PIM nie zna, siedzą w zakładce
**Do zmapowania**.

**5. Harmonogram** — PowerShell **jako administrator**:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\zainstaluj-zadanie.ps1 -Database NAZWA_BAZY
```

Domyślnie co 15 minut, jako SYSTEM (działa przy nikim niezalogowanym). Jeśli SQL
nie puszcza konta SYSTEM, dodaj `-RunAsUser DOMENA\konto` — skrypt zapyta o hasło.

## Jak to działa po stronie PIM

- **Paczka jest migawką całego magazynu, nie różnicą.** Czego w niej nie ma, tego
  PIM uznaje za zero. Dlatego pusty wynik zapytania **nie jest wysyłany** —
  pusty wynik to zwykle zły symbol albo brak uprawnień, a nie pusty magazyn.
  Bramka stoi po obu stronach: skrypt przerywa, a PIM i tak odrzuciłby taką paczkę.
- **Symbol magazynu bierze się z PIM**, nie z tego pliku. Skrypt najpierw pinguje
  i dostaje symbol w odpowiedzi. Zmiana w Ustawieniach dociera tu sama.
- **Mapowanie idzie automatem**: kod z Subiekta równy `product_code` w PIM dopina
  się sam. Reszta ląduje w zakładce **Do zmapowania** i tam przypisuje się ręcznie.
  Ręczne przypisanie zawsze przebija automat.

## Błędy

| Wynik | Znaczenie | Co zrobić |
|---|---|---|
| `BLAD 401` | PIM nie uznał tokenu | sprawdź `token.txt` — nowy token unieważnia stary |
| `BLAD 403` | token dobry, połączenie wyłączone w PIM | włącz *Połączenie aktywne* |
| `BLAD wysylki (422)` | symbol magazynu w paczce ≠ symbol w PIM | zrównaj jedno z drugim |
| `PRZERWANE: zapytanie nie zwrocilo ani jednej pozycji` | zły symbol, zła baza albo brak uprawnień | uruchom `sprawdz-magazyny.ps1` |
| błąd o nieznanej tabeli | układ bazy inny niż zakładamy | wyślij wynik `sprawdz-magazyny.ps1`, poprawimy zapytanie |

Logi: `stan.log` i `ping.log` obok skryptów.
