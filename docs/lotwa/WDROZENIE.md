# Wdrożenie LV + ET na produkcję — procedura

Stan na 2026-08-10: **kod i skrypty gotowe, nic jeszcze nie wypchnięte**. Pre-flight przeszedł,
poniżej dokładna kolejność, kontrole i rollback.

## Pre-flight — wykonany 2026-08-10

Sprawdzone na **danych pobranych z produkcji**, nie na lokalnej bazie (prod ma 1635 produktów
wobec 1494 lokalnie i 166 wartości `engine` wobec 129):

| Kontrola | Wynik |
|---|---|
| Frazy matrycy pokryte słownikiem | 34/34 dla LV, 34/34 dla ET |
| Wartości `protection` | 48/48 w obu językach |
| Wartości `engine` | 27 fraz + 12 podmian słów; 16 zostaje 1:1 (kody: `2.0TFSI`, `quattro`, `PHEV`, `XDrive`, `Dual Motor`) |
| Nazwy atrybutów | 9/9 |
| Locki `lv`/`et` w `translation_overrides` | 0 — nic nie zablokuje zapisu |
| Szablony `bsp-lv` / `bsp-et` | nie istnieją na prodzie (mapowanie po `slug`, więc brak kolizji z `bsp-pl` id 13 i `bsp-lt` id 14) |
| Kanały w `translation_phrase_renditions` | bez `lv`/`et` — seed wchodzi na puste |

Pre-flight wykrył, że produkcja ma 37 wartości `engine` więcej niż lokalna baza
(`3.0 diesel`, `Benzina`, `non-hybrid`, `pentru toate motorizari RS`). Same słowniki fraz
by ich nie pokryły — stąd dołożona podmiana na poziomie słów (`$ENGINE_WORDS`).

Do ręcznej oceny zostaje jedna wartość: **`Fara laterale`** — rumuński wpis w polu silnika,
najpewniej śmieć w danych. Skrypt sam ją wypisze w sekcji „do oceny".

## Znane różnice prod vs lokalnie

- **148 slotów `name.lv` i 148 `name.et`** na prodzie zawiera polskie śmieci z feedu
  (`„Stalowa Osłona pod silnik aluminium Mercedes…"`). Nie mają locków → zostaną nadpisane. Pożądane.
- **1596 produktów ma lock `auto_matrix` na `name.pl`** — a `auto_matrix` **nie chroni** PL przed
  composerem (chronią tylko `manual` i `sheet_import`). Lokalnie `straightenPl` okazał się
  idempotentny i diff wyszedł pusty, ale prod ma inne dane. **Stąd obowiązkowy dump przed krokiem 6.**

## Procedura

Kroki 1–5 są bezpieczne i odwracalne. Ryzyko zaczyna się przy kroku 6.

```bash
# 1. DUMP (na prodzie) — bez tego nie zaczynamy
cd ~/domains/pim.bsplate.eu/PIM
/usr/local/php83/bin/php artisan tinker --execute='
  $b = "/home/admin/backup-pim-lv-et-" . date("Ymd-His");
  mkdir($b);
  file_put_contents("$b/products_name.json", DB::table("products")->pluck("name","id")->toJson());
  file_put_contents("$b/renditions.json", DB::table("translation_phrase_renditions")->get()->toJson());
  file_put_contents("$b/templates.json", DB::table("templates")->get()->toJson());
  echo $b . PHP_EOL;
'

# 2. PUSH (lokalnie) — kod wchodzi bez efektu, dopóki nie ma renditcji w bazie
git add app/Services/ProductTranslationComposer.php app/Services/PhraseRenditionDeriver.php \
        app/Http/Controllers/Admin/TranslationPhraseController.php \
        app/Console/Commands/TranslationsRepairEncoding.php \
        app/Console/Commands/TranslationsAutoTranslate.php \
        deploy/lv-*.php deploy/et-*.php docs/lotwa/ docs/estonia/
git commit && git push

# 3. Poczekać na auto-deploy (cron co 5 min) i POTWIERDZIĆ, że kod dojechał
git log --oneline -1          # musi pokazać nowy commit
tail -5 storage/logs/deploy.log

# 4. DRY-RUNY — wszystkie sześć musi wyjść bez sekcji "!!!"
for s in lv-matrix lv-attributes lv-template et-matrix et-attributes et-template; do
  /usr/local/php83/bin/php deploy/$s-seed.php
done

# 5. APPLY seedów
for s in lv-matrix lv-attributes lv-template et-matrix et-attributes et-template; do
  /usr/local/php83/bin/php deploy/$s-seed.php --apply
done

# 6. SKLEJENIE NAZW — jedno przejście wypełnia oba języki naraz
/usr/local/php83/bin/php artisan translations:auto-translate
```

Kolejność nie jest dowolna: `auto-translate` czyta renditcje z matrycy, więc musi iść po seedach,
a seedy po deployu kodu (bez `lv`/`et` w `WRITABLE_LOCALE_CHANNELS` composer ich nie zapisze).

## Weryfikacja po wdrożeniu

```bash
/usr/local/php83/bin/php artisan tinker --execute='
  $stare = json_decode(file_get_contents("<KATALOG_DUMPU>/products_name.json"), true);
  $zmiany = 0;
  foreach (DB::table("products")->get(["id","name"]) as $p) {
      $a = json_decode($stare[$p->id] ?? "{}", true)["pl"] ?? null;
      $b = json_decode($p->name, true)["pl"] ?? null;
      if ($a !== $b) { $zmiany++; echo "PL ZMIENIONE id={$p->id}\n  BYLO: $a\n  JEST: $b\n"; }
  }
  echo "Zmian w PL: $zmiany  (oczekiwane: 0)\n";
'
```

Do sprawdzenia poza tym: liczniki `name.lv` i `name.et` (oczekiwane ~1632), próbka nazw
per typ frazy, render obu szablonów na produkcie stalowym i aluminiowym.

## Rollback

```sql
-- nazwy: przywrócić products.name z dumpu (products_name.json), per id
DELETE FROM translation_phrase_renditions WHERE channel IN ('lv','et');
DELETE FROM translation_overrides        WHERE locale  IN ('lv','et');
DELETE FROM templates                    WHERE slug    IN ('bsp-lv','bsp-et');
```

Kod można zostawić — bez renditcji w bazie composer nie ma czym wypełnić `lv`/`et` i zachowuje
się jak przed zmianą. Jedyny widoczny efekt to dwie dodatkowe kolumny w UI matrycy i szerszy
licznik pokrycia w review queue.

## Czego wdrożenie NIE robi

Nie podpina szablonów do integracji — sklepów LV i ET jeszcze nie ma. Gdy powstaną:
Argo Connect → Integracje → źródło → pole „Szablon" → `BSP - LV` / `BSP - ET`.
