# Problem ze zdjęciami produktów (Sumpguard) — diagnoza i naprawa

> Data: 2026-05-22
> Środowisko: produkcja `pim.bsplate.eu`
> Status: **ROZWIĄZANE** ✅

---

## TL;DR

1. **Część produktów Sumpguard nie miała zdjęć** → dociągnięte backfillem (37 produktów, 242 pliki).
2. **Zdjęcia (zwłaszcza nowe) nie wyświetlały się / dawały 404** → bo **nginx serwował starą KOPIĘ katalogu mediów** (`public_html/media`), a aplikacja zapisywała do `PIM/public/media`. Naprawione **symlinkiem**.
3. **Nowe produkty będą miały działające zdjęcia**, pod warunkiem że symlink przetrwa kolejne deploye (deploy tylko do `PIM/`, nie ruszać `public_html`).

---

## Środowisko (fakty produkcyjne)

| Co | Wartość |
|---|---|
| Host | `pareto` (panel **DirectAdmin**) |
| Katalog aplikacji (Laravel) | `/home/admin/domains/pim.bsplate.eu/PIM/` |
| Katalog serwowany przez WWW (docroot) | `/home/admin/domains/pim.bsplate.eu/public_html/` |
| PHP CLI | `/usr/local/php83/bin/php` (8.3) |
| Baza | MySQL, `enabled` źródła Sumpguard = 1 |
| Deploy | ręczne wrzucanie plików po SSH do `PIM/` |
| Sterownik obrazów (media-library) | GD (działa); brak optymalizatorów na PATH (nieistotne) |
| CLI `memory_limit` | 1024M |

Komendy artisan odpalać z katalogu projektu albo pełną ścieżką:
```bash
cd /home/admin/domains/pim.bsplate.eu/PIM
/usr/local/php83/bin/php artisan ...
```

---

## Problem 1 — brak zdjęć dla części produktów

**Objaw:** 37 produktów Sumpguard (id `130782`–`130843`, zwarty blok) nie miało żadnych mediów. Starsze i nowsze produkty miały (max id z mediami = `131163`).

**Przyczyna:** jeden przebieg synca dla tej partii się wyłożył (błąd przejściowy), a `SumpguardSource::getImages()` było wołane **tylko przy tworzeniu** produktu (gałąź `else`), nigdy przy aktualizacji — więc braki nigdy się nie naprawiały. Do tego błędy były połykane do `dump()` (zero logów).

**Naprawa (kod, `app/Sources/SumpguardSource.php`):**
- `getProducts()` — dochodzi **self-heal**: jeśli istniejący produkt nie ma mediów, `getImages()` jest wołane też przy aktualizacji.
- `getImages()` — błędy do `Log::warning` zamiast `dump()`, łapanie `\Throwable`, zabezpieczenie pustego `images`.
- nowa metoda `backfillMissingImages()` + komenda `sumpguard:backfill-images`.

**Backfill wykonany:**
```
php artisan sumpguard:backfill-images --items
=> Bez mediów: 37 | przetworzono: 37 | podpięto plików: 242 | pominięto: 0
weryfikacja: produktów bez mediów = 0
```

---

## Problem 2 (główny) — zdjęcia dają 404 / nie wyświetlają się

**Objaw:** w adminie wszystkie miniatury puste; URL zdjęcia np.
`https://pim.bsplate.eu/media/10646/...jpg` → **404**, mimo że plik fizycznie był na dysku (`is_file=YES`).

### Co NIE było przyczyną (sprawdzone i odrzucone)
- **Podwójne rozszerzenie `.jpg.jpg`** — feed Sumpguard podaje takie URL-e, ale to nie to: skopiowany ten sam plik pod pojedynczą nazwą `zzztest.jpg` w tym samym katalogu **też dał 404**.
- **Nieudane konwersje (miniatury)** — pliki (oryginał + `conversions/`) były na dysku, GD działa.
- **Złe nazwy ze źródła** — źródłowe URL-e działają (otwierają się), pliki pobrały się poprawnie.

### Prawdziwa przyczyna: rozjechane katalogi mediów

DirectAdmin serwuje WWW z `public_html`, a `public_html` było **KOPIĄ** `PIM/public` (a nie symlinkiem). Katalog `media` się rozjechał:

| Katalog | Rola | Wpisy | Data | ma `media/4` | ma `media/10646` |
|---|---|---|---|---|---|
| `public_html/media` | **serwuje nginx** | 8590 | 14 maja (zamrożony) | ✅ | ❌ |
| `PIM/public/media` | **zapisuje aplikacja** | 9284 | 22 maja (żywy) | ✅ | ✅ |

→ nginx oddawał starą kopię. Stare zdjęcia (`/media/4/`) działały (są w obu), nowe (`/media/10646/`, dzisiejsze) → 404, bo w serwowanej kopii ich nie było.

Dowód rozstrzygający:
```
https://pim.bsplate.eu/media/4/...      → 200  (stare, jest w public_html/media)
https://pim.bsplate.eu/media/10646/...  → 404  (nowe, tylko w PIM/public/media)
```

### Naprawa — symlink

```bash
cd /home/admin/domains/pim.bsplate.eu/public_html
rm -f media/media                       # usunięcie przypadkowego zagnieżdżonego linku
mv media media.OLD-20260522             # backup starej kopii (NIE kasować od razu)
ln -s /home/admin/domains/pim.bsplate.eu/PIM/public/media media
```

Weryfikacja (oba 200):
```
media -> /home/admin/domains/pim.bsplate.eu/PIM/public/media
nowe(10646): 200
stare(4):    200
```

> `PIM/public/media` (9284) jest nadzbiorem starej kopii (8590), więc nic nie tracimy. `media.OLD-20260522` zostawić kilka dni jako backup, potem `rm -rf`.

---

## Czy nowe produkty będą miały dobre zdjęcia?

**TAK** — pod warunkiem utrzymania symlinka.

- Nowy produkt → nocny `sources:sync` (01:00) → `getImages()` pobiera → plik ląduje w `PIM/public/media/{id}/` → serwowany przez symlink → **200**.
- Podwójne rozszerzenie `.jpg.jpg` **nie szkodzi** (potwierdzone: plik `10646` z `.jpg.jpg` po symlinku = 200).
- Self-heal (jest na prodzie) ratuje produkty, dla których pobranie chwilowo padło.

### ⚠️ Jedyne ryzyko: symlink musi przetrwać deploy

`public_html` to kopia `PIM/public`. Jeśli deploy kiedyś **odtworzy/skopiuje** `public_html`, nadpisze symlink `media` zwykłym katalogiem → nowe zdjęcia znów dadzą 404.

**Zasada:** deployować wyłącznie do `PIM/`, **nie wrzucać niczego do `public_html`**.

**Siatka bezpieczeństwa** (idempotentna, do odpalenia po większym deployu):
```bash
ln -sfn /home/admin/domains/pim.bsplate.eu/PIM/public/media /home/admin/domains/pim.bsplate.eu/public_html/media
```
(jeśli `media` jest symlinkiem — odświeży; jeśli ktoś podmienił na realny katalog — komenda celowo się wywali, sygnalizując problem)

**Docelowo (opcja, raz a dobrze):** całe `public_html` zrobić symlinkiem na `PIM/public` (albo ustawić docroot domeny na `PIM/public`) — wtedy nic się nie kopiuje i nie może się rozjechać. Wymaga sprawdzenia zawartości `public_html` + backupu + weryfikacji, że strona wstaje.

---

## Stan kodu na produkcji (na 2026-05-22)

`app/Sources/SumpguardSource.php`:
- ✅ self-heal (`getImages` przy braku mediów) + logowanie + `backfillMissingImages` — **wgrane**.
- ❌ `cleanImageFilename()` (czyszczenie nazw do pojedynczego rozszerzenia) + `redownloadBadlyNamedImages()` — **NIE wgrane**.

> Czyszczenie nazw to **kosmetyka, nie warunek działania** (zdjęcia `.jpg.jpg` serwują się normalnie przez symlink). Wgranie aktualnego `SumpguardSource.php` jest opcjonalne — da czystsze nazwy nowych plików, ale nie jest potrzebne do poprawnego wyświetlania. **Nie ma potrzeby uruchamiać `--bad-names`.**

---

## Kontekst: po co to wszystko (eksport do sklepów)

PIM (master produktów) **wypycha produkty do sklepów PrestaShop** przez konektor:
- `CatalogCreateJob(<integration_id>)` → POST na `{integration->url}/pim-connector-presta.php` (kod: `AbstractHttpConnector::performRequest`).
- W payloadzie zdjęcia idą jako **URL-e** (`source_url = $media->getFullUrl()` = `https://pim.bsplate.eu/media/...`); skrypt sklepu **sam pobiera** zdjęcia z tych URL-i.
- Dlatego naprawa serwowania mediów (symlink) jest kluczowa także dla importu zdjęć **po stronie sklepów** — wcześniej sklep dostawał 404.

Rollout planowany na **10 sklepów** (integracji PrestaShop).

---

## Otwarte / do zrobienia (pod 10 sklepów)

- [ ] **Utrwalić symlink** mediów tak, by przetrwał deploy (zasada „deploy tylko do `PIM/`" + ewentualnie docroot na `PIM/public`).
- [ ] **Workery kolejek** — joby łańcucha lecą na kolejki `sync-catalog` / `sync-media` / `sync-blog`, a zaplanowany w `Kernel.php` `queue:work` słucha tylko `default`. Zweryfikować, czy prod ma osobne workery (supervisor), bo inaczej nocny `integrations:sync` (03:00) nie przetworzy łańcucha. (`ps -ef | grep '[q]ueue:work'`)
- [ ] Zweryfikować end-to-end dla referencyjnej integracji: `CatalogDeltaJob` + `MediaSyncJob` (galeria do sklepu).
- [ ] Kosmetyka: zdublowana komenda `integrations:sync` (dwa identyczne pliki: `IntegrationsSync.php`, `SyncIntegrations.php`), martwy `SyncService` z odwołaniem do nieistniejącej relacji `Attribute::group`, oraz flood deprecation `strlen/trim/hash(null)` z `View/Component.php` (zalewa logi, maskuje realne błędy).
- [ ] Ujednolicić ręczną poprawkę `AbstractHttpConnector` (hardcode URL zamiast `route()`) z repo + upewnić się, że trasy `update-connector.*` są w `routes/web.php` na prodzie (były dodane ręcznie + `route:clear`).
