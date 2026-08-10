# Rynek estoński (locale `et`) — terminologia

Data: 2026-08-07

Mechanizm, skrypty i zasady: [`docs/lotwa/README.md`](../lotwa/README.md) — estoński powstał tą samą
drogą co łotewski, ten dokument pokrywa wyłącznie decyzje językowe.

## Forma nazwy

```
Terasest mootori kaitse Audi A4 B9
Alumiiniumist käigukasti kaitse Ford Ranger Raptor
Terasest mootori ja käigukasti kaitse Kia Sorento
Terasest mootori kaitse koos Webastoga
```

Materiał w **elatiivie** (`terasest` = ze stali, `alumiiniumist` = z aluminium), element
w **genitiivie**, rdzeń `kaitse` na końcu. Estoński jest head-final tak samo jak łotewski,
więc obsługuje go ta sama stała `HEAD_FINAL_CHANNELS`.

## Dlaczego nie „karterikaitse"

Na estońskim rynku dominuje `karterikaitse` — to nazwa kategorii na auto24.ee, domena
karterikaitse.ee, kategoria u koneskoauto.ee. Kusi pod SEO, ale `karter` to **miska olejowa**:
do osłony skrzyni biegów, dyferencjału czy chłodnicy ten termin jest po prostu nieprawdziwy,
a my mamy w katalogu 17 różnych elementów. Ta sama decyzja co przy łotewskim `kartera aizsargs`.

`mootori kaitse` używa tych samych słów co `mootorikaitse` (drugi z częstych wariantów rynkowych),
więc wyszukiwarki nie mają problemu, a forma skaluje się na wszystkie elementy.

Rozważana była forma złożona (`mootorikaitse`, `käigukastikaitse`) — odpadła, bo przy kombinacji
elementów i tak trzeba rozdzielić (`mootori ja käigukasti kaitse`), a deriver nie potrafi wsunąć
sufiksu do środka zrośniętego słowa.

## Słownik elementów

| PL | ET (genitiiv) |
|---|---|
| silnik | mootori |
| skrzynia biegów | käigukasti |
| zbiornik paliwa | kütusepaagi |
| AdBlue | AdBlue paagi |
| dyferencjał | diferentsiaali |
| katalizator | katalüsaatori |
| chłodnica | radiaatori |
| reduktor | reduktori |
| DPF | DPF-filtri |
| EGR | EGR-klapi |
| przedni zderzak | esistangi |
| akumulator | aku |
| filtr paliwa | kütusefiltri |
| skrzynka transferowa | jaotuskasti |
| czujnik tylnego wahacza | tagumise õõtshoova anduri |

Modyfikatory: `koos Webastoga`, `Start-Stop süsteemiga`, `tsinkkattega`, `ja käigukasti`.

## Źródła terminologii

auto24.ee (kategoria „Karteri kaitsed"), karterikaitse.ee, autokaitse.ee, koneskoauto.ee,
alvadi.ee, tirespot.ee.
