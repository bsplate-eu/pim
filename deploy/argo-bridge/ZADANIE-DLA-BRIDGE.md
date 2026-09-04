# Zadanie dla ARGO Bridge: wysyłka stanu magazynu do PIM

Pilot `0.1.0` inwentaryzuje **schemat** bazy (`SqlReadOnlyProbe` robi dwa zapytania:
wersja serwera + liczba tabel/kolumn z `INFORMATION_SCHEMA`), a `DiagnosticReport`
niesie tylko dane o maszynie i sekcje sond. **Nie ma tam ani jednej ilości.**
Trzeba dołożyć odczyt stanów i wysyłkę do PIM. Po stronie PIM wszystko już czeka.

## Co ma powstać

Nowa sonda/moduł: **odczyt stanu jednego magazynu z Subiekta GT i POST do PIM.**
Wyłącznie odczyt — do ERP nie zapisujemy, Sfery do tego nie ruszamy.

## 1. Skąd wiadomo, który magazyn

Nie zaszywać symbolu w kodzie. Bridge najpierw pinguje PIM i **dostaje symbol w
odpowiedzi** — dzięki temu zmiana w PIM dociera sama, bez ruszania konfiguracji.

```
POST https://pim.bsplate.eu/api/argo-bridge/ping
Nagłówki: X-Argo-Token: <token>, Content-Type: application/json
Body:     {"version":"0.2.0"}

200 → {"ok":true,"warehouse_symbol":"M3R","server_time":"2026-09-04T08:54:21+00:00"}
401 → zły albo brak tokenu
403 → token dobry, ale połączenie wyłączone w PIM (nie ponawiać z innym tokenem)
```

Token wkleja się w PIM: **Magazyn → Ustawienia → Argo Bridge → Generuj token**.
Trzymać go poza repozytorium (plik obok exe albo ustawienie aplikacji).

## 2. Zapytanie do Subiekta (read-only)

Połączenie: `ApplicationIntent=ReadOnly`, `SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED`
— nie blokujemy pracy w Subiekcie.

```sql
SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED;
SELECT t.tw_Symbol AS code, t.tw_Nazwa AS name, SUM(s.st_Stan) AS qty
FROM tw_Stan s
INNER JOIN tw__Towar t  ON t.tw_Id  = s.st_TowId
INNER JOIN sl_Magazyn m ON m.mag_Id = s.st_MagId
WHERE m.mag_Symbol = @symbol
GROUP BY t.tw_Symbol, t.tw_Nazwa
HAVING SUM(s.st_Stan) <> 0
ORDER BY t.tw_Symbol
```

> ⚠️ **Filtrować po `mag_Symbol`, nigdy po numerze z nazwy.** Numer w symbolu nie
> odpowiada `mag_Id` — M3 potrafi mieć id 2, w numeracji są dziury. Kto założy,
> że M3 → 3, ten pobierze stan zupełnie innego magazynu.

Jeśli układ tabel okaże się inny niż wyżej — wypisać z `INFORMATION_SCHEMA` tabele
pasujące do `tw_%` i `sl_%` i dopasować nazwy. Reszta kontraktu zostaje bez zmian.

## 3. Format i wysyłka

```
POST https://pim.bsplate.eu/api/argo-bridge/stock
Nagłówki: X-Argo-Token: <token>
          Content-Type: application/json; charset=utf-8
```

```json
{
  "warehouse": "M3R",
  "version": "0.2.0",
  "items": [
    { "code": "00.004", "name": "Stalowa osłona skrzyni biegów Audi A4 B7", "quantity": 12 },
    { "code": "07.043", "name": "Stalowa osłona silnika", "quantity": 1.5 }
  ]
}
```

Walidacja po stronie PIM:

| Pole | Wymagania |
|---|---|
| `warehouse` | wymagane; musi zgadzać się z symbolem ustawionym w PIM (bez znaczenia wielkość liter), inaczej **422** |
| `items` | wymagane, **minimum 1 pozycja** — pusta lista to **422** |
| `items[].code` | wymagane, string, max 100 |
| `items[].name` | opcjonalne, string, max 255 |
| `items[].quantity` | wymagane, liczba (ułamki dozwolone) |

Odpowiedź 200: `{"ok":true,"received":1873,"stored":1873,"server_time":"..."}`
Kody błędów: **401** zły token, **403** połączenie wyłączone w PIM, **422** zła
walidacja albo niezgodny symbol magazynu (treść w polu `error` mówi konkretnie co).

## 4. Zasady, których nie wolno złamać

1. **Paczka jest MIGAWKĄ całego magazynu, nie różnicą.** PIM kasuje wszystko,
   czego w paczce nie było — czyli towar zdjęty w ERP znika też u nas. Nie wysyłać
   przyrostów ani „tylko zmienionych".
2. **Pusty wynik zapytania NIE jedzie.** Zero pozycji to prawie zawsze zły symbol,
   zła baza albo brak uprawnień — nie pusty magazyn. Przerwać z komunikatem.
   (PIM i tak odrzuci taką paczkę 422, ale bramka ma stać po obu stronach.)
3. **Tylko odczyt.** `ApplicationIntent=ReadOnly`, żadnego INSERT/UPDATE, żadnej
   Sfery przy tej operacji.
4. **Token nie ląduje w repozytorium ani w raporcie JSON.** `DiagnosticReport.Redact`
   już czyści `token`/`password` — nowego kanału nie omijać.
5. Duplikaty kodów w jednej paczce PIM sumuje. Można je wysłać, ale lepiej zsumować
   po swojej stronie (`GROUP BY` wyżej to robi).

## 5. Jak sprawdzić, że działa

Po udanym POST w PIM (**Magazyn → Ustawienia → Argo Bridge**) pojawia się czas
ostatniej paczki i liczba pozycji, a w **Magazyn → Magazyn M3R** kolumna
**Stan M3R** zapełnia się liczbami. Kody, których PIM nie zna, lądują w zakładce
**Do zmapowania** — to normalne, nie błąd; mapuje się je ręcznie i takie
przypisanie przebija potem dopasowanie automatyczne.

## 6. Jeśli na razie ma być tylko plik JSON

Można w pierwszym kroku zrobić sam eksport do pliku w **dokładnie tym formacie**
co wyżej — wtedy wysyłka to jedna linijka z tej samej maszyny:

```powershell
$body = Get-Content .\stan.json -Raw
Invoke-RestMethod -Method Post -Uri https://pim.bsplate.eu/api/argo-bridge/stock `
  -Headers @{'X-Argo-Token'='<token>'} -ContentType 'application/json; charset=utf-8' `
  -Body ([Text.Encoding]::UTF8.GetBytes($body))
```

Docelowo lepiej, żeby Bridge wysyłał sam i robił to cyklicznie — plik pośredni
tylko opóźnia moment, w którym stan się rozjeżdża z rzeczywistością.
