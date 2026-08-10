# Rynki bałtyckie: łotewski (`lv`) i estoński (`et`)

Data: 2026-08-07

> Ten dokument opisuje mechanizm dla obu języków — estoński powstał tą samą drogą, dzień w dzień.
> Terminologia estońska i jej uzasadnienie: [`docs/estonia/README.md`](../estonia/README.md).

Oba wpięte **w matrycę tłumaczeń**, a nie jako jednorazowy import z CSV (tak jak zrobiono
litewski). Różnica praktyczna: nazwy wypełniają się same także dla każdego nowego produktu
z feedu Sumpguard, bez powtarzania importu.

## Forma nazwy

```
Tērauda dzinēja aizsargs Audi A4 B9
Alumīnija dzinēja aizsargs Mercedes V-Class W447, 4x4
Tērauda ātrumkārbas un reduktora aizsargs Kia Sorento
```

Odpowiednik litewskiego „Plieninė variklio apsauga X". Terminologia z rynku LV
(efarkop.lv, rlm.lv, e-autoplus.lv, ss.com): `dzinēja` / `ātrumkārbas` / `radiatora aizsargs`.

Łotewski ma szyk **head-final** — rdzeń `aizsargs` stoi na końcu, więc określenia go poprzedzają.
To jedyna strukturalna różnica wobec DE/CS/SK/FR/ES i jedyny powód zmian w deriverze.

## Zmiany w kodzie

| Plik | Co |
|---|---|
| `app/Services/ProductTranslationComposer.php` | `lv`, `et` w `LOCALE_CHANNELS` i `WRITABLE_LOCALE_CHANNELS` |
| `app/Services/PhraseRenditionDeriver.php` | `MATERIAL_MAP['lv'\|'et']`, oba w `SUFFIX_TRANSLATIONS`, `HEAD_FINAL_CHANNELS` + `NOMINAL_SUFFIXES` + `attachSuffix()` |
| `app/Http/Controllers/Admin/TranslationPhraseController.php` | kolumny `LV` i `ET` w UI matrycy |
| `app/Console/Commands/TranslationsRepairEncoding.php` | lista „matrycowych" locale z composera, nie z `LOCALE_FEED` — inaczej `--clear-non-matrix` traktowałby `lv`/`et` jak śmieciowe locale |

`HEAD_FINAL_CHANNELS` rozwiązuje to, że sufiks nominalny musi wejść **przed** rdzeń:

```
„Tērauda dzinēja aizsargs" + „un ātrumkārbas" → „Tērauda dzinēja un ātrumkārbas aizsargs"
„Terasest mootori kaitse"  + „ja käigukasti"  → „Terasest mootori ja käigukasti kaitse"
```

Doklejenie na końcu dałoby formy niegramatyczne. Okoliczniki — `ar Webasto` / `koos Webastoga`,
`Start-Stop sistēmai` / `Start-Stop süsteemiga`, `ar cinkojumu` / `tsinkkattega` — zostają na końcu,
tak jak w pozostałych kanałach.

To jedyna strukturalna różnica wobec DE/CS/SK/FR/ES. Kolejny język head-final wystarczy dopisać
do tej stałej i podać jego sufiksy.

## Skrypty (idempotentne, domyślnie dry-run)

```bash
php deploy/lv-matrix-seed.php --apply && php deploy/lv-attributes-seed.php --apply && php deploy/lv-template-seed.php --apply
php deploy/et-matrix-seed.php --apply && php deploy/et-attributes-seed.php --apply && php deploy/et-template-seed.php --apply
php artisan translations:auto-translate
```

Kolejność ma znaczenie: `auto-translate` na końcu, bo czyta renditcje z matrycy. Jedno przejście
wypełnia wszystkie brakujące locale naraz — nie trzeba go powtarzać per język.

`*-matrix-seed.php` kończy się kodem 1, jeśli któraś fraza w bazie nie ma tłumaczenia w słowniku —
wtedy dopisz ją w tym pliku i uruchom ponownie (nie ma cichego pomijania).

Skrypty `lv-*` i `et-*` są bliźniacze — różnią się wyłącznie słownikami. Przy trzecim rynku warto je
złożyć w jeden sparametryzowany `--locale=` ze słownikami w osobnych plikach.

## Szablony `bsp-lv` i `bsp-et`

Treść w repo: `docs/lotwa/szablon_bsp_lv.blade.html` i `docs/estonia/szablon_bsp_et.blade.html`
(pliki są źródłem prawdy, baza kopią roboczą). Odpowiedniki `bsp-lt`, ale rozpoznanie aluminium
działa poprawnie:

```blade
@php($is_alu = str_starts_with(mb_strtolower((string) ($attribute_material ?? '')), 'alum'))
```

Pipeline renderuje szablon w locale szablonu (`app()->setLocale($source->template->locale)`),
więc `$attribute_material` przychodzi już przetłumaczone — `Alumīnijs` dla `lv`, `Alumiinium`
dla `et`, nie `Aluminium`.

> ⚠️ **`bsp-lt` (id 14 na prodzie) ma tu buga**: porównuje `$attribute_material == 'Aluminium'`,
> a przy `locale=lt` atrybut zwraca `Aliuminis`. Warunek nigdy nie jest prawdziwy, więc wszystkie
> aluminiowe produkty na sklepie litewskim dostają opis stalowy („Plienas, labai tvirtas ir elastingas").
> Fix = ta sama linijka co wyżej. Nietknięte — poza zakresem tego zadania.

## Tłumaczenia atrybutów

Bez nich opis wyglądał tak:
`Aizsargājamie šasijas elementi: silnik, skrzyni?? bieg??w, Radiator` — Spatie schodził do fallbacku
i wchodziła polsko-angielska mieszanka z popsutym kodowaniem.

`{lv,et}-attributes-seed.php` uzupełniają slot **merge per-slot** (reszta JSON-a nietknięta):

- nazwy 9 atrybutów (`protection` → `Aizsargājamie elementi` / `Kaitstavad osad`, …),
- 48/48 wartości `protection`,
- 129/129 wartości `engine` — ~26 przetłumaczonych, reszta to kody techniczne
  (`1.6,1.8 Turbo, 2.0, 1.9 TDI`), kopiowane 1:1 z PL, żeby zdjąć fallback.

`make` i `model` zostają nietłumaczone — to nazwy własne.

## Stan po wdrożeniu lokalnym

- 1491 / 1494 produktów ma `name.lv` **i** `name.et` (3 bez `source_id`, poza feedem Sumpguard)
- nazwy PL: **zero zmian** (diff przed/po pusty — kontrola po incydencie z 2026-07-02);
  przy dokładaniu `et` sprawdzone również, że `lv` pozostało nietknięte
- matryca: 33/33 fraz lokalnie ma renditcje LV i ET (prod ma 34. frazę, jest w obu słownikach)

## Czego tu NIE ma

- **integracji / sklepów LV i ET** — szablony nie są podpięte do żadnego `integration_source`.
  Gdy powstanie sklep: Argo Connect → Integracje → źródło → pole „Szablon" → `BSP - LV` / `BSP - ET`.
- **`lt` w matrycy** — litewski nadal działa na jednorazowym imporcie, więc nowe produkty
  nie dostają nazwy LT. Wpięcie go w matrycę to ta sama robota co tutaj (34 frazy + kanał).
- **`lv` w `TranslationsAutoApprove::FOREIGN`** — celowo. Ta stała jest progiem zatwierdzania
  produktów do eksportu; dorzucenie 6. locale przy domyślnym `--min-foreign=5` rozluźniłoby próg.
