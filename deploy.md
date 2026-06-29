# Deploy PIM na produkcję — dziennik wdrożenia (2026-05-20/21)

Dokument opisuje **co było robione** przy wdrożeniu PIM na produkcję oraz **wszystkie napotkane problemy** z przyczynami i rozwiązaniami. Na końcu — poprawna procedura do powtórzenia następnym razem.

---

## 1. Kontekst

- **Cel:** wdrożenie na produkcję wszystkich zmian z lokalu (4 moduły, ~117 zmienionych plików od 2026-05-19).
- **Źródło (local):** `D:\laragon\www\PIM`, baza `pareto`, PHP 8.3.30, Node 18 (Laragon `C:\laragon`).
- **Repo:** brak git → zmiany wykrywane po dacie modyfikacji plików.

### Zakres (co wdrażano)
| Moduł | Zawartość |
|---|---|
| Argo Connect | Zamówienia, Klienci, Mapa, Integracje BaseLinker (multi-base), Faktury/Korekty, miniatury produktów |
| Argo HQ | CostPlanner, BankStatement, Kasa, OdysseyCost, Summaries |
| Shared/Layout | Sidebar + SubGroup, PageContent (full-width `fluid`), Listing (zebra `striped`), Authenticated layout |
| Integration | IntegrationController, IntegrationSource, IntegrationSyncLog (status sync), szyfrowanie sekretów |

---

## 2. Środowisko produkcyjne (pareto.ovh / DirectAdmin)

- **Root projektu Laravel:** `/home/admin/domains/pim.bsplate.eu/PIM` (tu jest `artisan`)
- **Web root (serwowany przez domenę):** `/home/admin/domains/pim.bsplate.eu/public_html`
- **`public_html/build`** → SYMLINK do `PIM/public/build`
- **`public_html/storage`** → SYMLINK do `PIM/storage/app/public`
- **`public_html/index.php`** — realny entry point (serwuje aplikację z `PIM`)
- **PHP:** `/usr/local/php83/bin/php` (8.3)
- **Baza:** `admin_pim` (phpMyAdmin pod `pareto.ovh/phpMyAdmin`)
- **OGRANICZENIA:**
  - brak `scp` na serwerze
  - brak `npm` / `node` na serwerze → **nie da się budować frontu na prodzie**
  - prod był cofnięty do stanu ~**14 maja** (nie tylko „od wczoraj")

---

## 3. Co zostało zrobione (przebieg)

1. Zbudowano lokalnie front: `npm run build` → `public/build/` (manifest + assets).
2. Wykryto zmienione pliki po dacie modyfikacji (brak gita).
3. Zbudowano paczkę `_deploy_2026-05-20/` (119 plików + dump geo) + `DEPLOY.md` + `FILES.txt`.
4. Wyeksportowano dump `geo_postal_codes` (data-only `geo_postal_codes_data.sql` oraz pełny `geo_postal_codes_FULL.sql`).
5. Spakowano front do `_deploy_PUBLIC_BUILD.zip` (skompilowane `public/build`).
6. Po serii problemów (sekcja 4) zbudowano **pełny sync backendu** `_deploy_FULL_backend.zip` (cały `app/ routes/ config/ lang/ database/migrations/`).
7. Na prodzie: wgranie buildu do `PIM/public/build`, pełny sync backendu, `migrate --force`, czyszczenie cache.

---

## 4. PROBLEMY napotkane (przyczyna → rozwiązanie)

### P1. Brak repozytorium git
- **Objaw:** `git status` → `fatal: not a git repository`.
- **Przyczyna:** projekt nie jest pod gitem.
- **Rozwiązanie:** zmiany wykrywane po `LastWriteTime` plików (PowerShell), pakowane ręcznie.

### P2. Paczka „od wczoraj" była NIEKOMPLETNA (najgroźniejszy problem)
- **Objaw:** po wgraniu plików menu się wywala, brakuje klas/tras.
- **Przyczyna:** prod był cofnięty do ~14 maja, a paczka zawierała tylko pliki zmienione od 19 maja. Pliki utworzone 14–19 maja (np. `IntegrationSyncLogController.php` z 2026-05-13) **nie weszły do paczki** ani nie były na prodzie. Nowe `routes/crafter.php` i Sidebar odwoływały się do nich.
- **Rozwiązanie:** **pełny sync backendu** (`_deploy_FULL_backend.zip` = cały `app/ routes/ config/ lang/ migrations`), nie filtrowany datą.
- **Wniosek:** przy prodzie cofniętym o więcej niż 1 dzień — NIE filtrować paczki datą; synchronizować cały kod.

### P3. Import dumpa geo: `#1146 Table 'admin_pim.geo_postal_codes' doesn't exist`
- **Objaw:** import `geo_postal_codes_data.sql` w phpMyAdmin → błąd 1146.
- **Przyczyna:** dump był **data-only** (`--no-create-info`, same INSERT-y). Tabela jeszcze nie istniała, bo nie uruchomiono migracji.
- **Rozwiązanie:** **najpierw** `php artisan migrate --force` (tworzy tabelę), **potem** import danych. Alternatywnie dump `geo_postal_codes_FULL.sql` (ze strukturą) lub `php artisan connect:import-postal-codes --all-eu`.

### P4. „W panelu nic nie widać" po wgraniu plików
- **Objaw:** wgrane pliki, a panel bez zmian.
- **Przyczyna:** wgrano źródła `resources/js/**`, ale przeglądarka ładuje **skompilowane** assety z `public/build/`. Tych nie wgrano, a na prodzie brak `npm` → nie da się zbudować.
- **Rozwiązanie:** wgranie lokalnie zbudowanego `public/build/` (`_deploy_PUBLIC_BUILD.zip`).

### P5. Struktura `public_html` vs `PIM/public` (symlink)
- **Objaw:** mylące dwa katalogi; po `rm -rf build` build zniknął.
- **Przyczyna:** domena serwuje z `public_html/`, gdzie `build` to **symlink** → `PIM/public/build`. `rm -rf build` w `PIM/public` skasował **cel** symlinka.
- **Rozwiązanie:** build rozpakować **do `PIM/public/`** (nie do `public_html`). Wtedy symlink znów działa.

### P6. `scp: command not found`
- **Objaw:** `scp ...` → `command not found`.
- **Przyczyna:** komenda `scp` uruchomiona **wewnątrz sesji SSH na prodzie** (prod nie ma scp), a powinna iść z **lokalnego** terminala.
- **Rozwiązanie:** upload przez DirectAdmin **File Manager** (najpewniejsze przy braku scp).

### P7. Śmieć z wklejania `^[[200~` psuje komendy
- **Objaw:** `^[[200~cd ...` → `cd: command not found`; potem `Could not open input file: artisan`.
- **Przyczyna:** bracketed-paste mode terminala wstawiał `^[[200~` na początku wklejanej komendy → `cd` nie wykonywał się → praca w złym katalogu (`PIM/public` zamiast `PIM`), gdzie nie ma `artisan`.
- **Rozwiązanie:** `printf '\e[?2004l'` (wyłącza bracketed paste) **oraz** uruchamianie artisana z **pełną ścieżką** (bez `cd`):
  `/usr/local/php83/bin/php /home/admin/domains/pim.bsplate.eu/PIM/artisan ...`

### P8. Puste menu boczne (Ziggy)
- **Objaw:** dashboard działa, ale lewy sidebar pusty.
- **Diagnoza (F12 → Console):** `Ziggy error: route 'crafter.integrations.status' is not in the route list`.
- **Przyczyna:** Sidebar woła `route('crafter.integrations.status')`, której na prodzie brak (skutek P2 — niekompletna paczka / stary `routes/crafter.php` / brak `IntegrationSyncLogController`). Jeden nieistniejący `route()` wywala CAŁY komponent menu.
- **Rozwiązanie:** pełny sync backendu (P2) → wszystkie trasy i kontrolery obecne → `route:clear` → menu wraca.
- **Wniosek:** brakująca trasa w sidebarze = blank menu. Diagnoza zawsze przez konsolę przeglądarki (F12).

### P9. Brak `npm`/`node` na prodzie
- **Objaw:** `npm: command not found`.
- **Przyczyna:** shared hosting bez Node.
- **Rozwiązanie:** budować front lokalnie i wgrywać `public/build/` (nigdy nie liczyć na build na prodzie).

---

## 5. POPRAWNA procedura wdrożenia (do powtórzenia)

> Założenie: prod cofnięty o kilka dni, brak git/scp/npm na serwerze, jest SSH + PHP 8.3 + phpMyAdmin.

### Krok 0 — Backup (obowiązkowo)
```bash
# baza (phpMyAdmin → Eksport, lub):
mysqldump -u admin_pim -p admin_pim > backup_pre_deploy.sql
# kod:
cd /home/admin/domains/pim.bsplate.eu
tar czf PIM_backup_$(date +%F).tar.gz PIM/app PIM/routes PIM/config PIM/database/migrations
```

### Krok 1 — Front (lokalnie zbudowany)
```bash
# LOCAL:
npm run build
# spakować public/build -> _deploy_PUBLIC_BUILD.zip
```
Upload zipa przez **File Manager** do `PIM/public/`, potem na prodzie:
```bash
printf '\e[?2004l'
cd /home/admin/domains/pim.bsplate.eu/PIM/public
unzip -o _deploy_PUBLIC_BUILD.zip   # tworzy PIM/public/build (symlink z public_html zadziała)
rm _deploy_PUBLIC_BUILD.zip
ls build                            # musi być manifest.json + assets
```

### Krok 2 — Backend (PEŁNY sync, nie filtrowany datą)
Spakować lokalnie cały `app/ routes/ config/ lang/ database/migrations/` → `_deploy_FULL_backend.zip`.
Upload przez File Manager do `PIM/`, potem:
```bash
cd /home/admin/domains/pim.bsplate.eu/PIM
unzip -o _deploy_FULL_backend.zip
rm _deploy_FULL_backend.zip
```

### Krok 3 — Baza + cache (pełna ścieżka do artisana)
```bash
P=/usr/local/php83/bin/php
A=/home/admin/domains/pim.bsplate.eu/PIM/artisan
$P $A migrate --force
$P $A route:clear
$P $A config:clear
$P $A view:clear
$P $A cache:clear
```

### Krok 4 — Dane mapy (geo_postal_codes)
```bash
# po migrate (tabela istnieje):
$P $A connect:import-postal-codes --all-eu     # ~5-15 min z GeoNames
# LUB import dumpa data-only przez phpMyAdmin (geo_postal_codes_data.sql)
```

### Krok 5 — Weryfikacja
```bash
$P $A route:list | grep -E "connect|integrations.status|cost-planner|kasa"
$P $A migrate:status | grep -E "connect|geo_postal|invoice|cost_planner|summary"
```
Panel: `Ctrl+Shift+R` → menu (Argo HQ / PIM / Connect) widoczne, `/admin/connect/orders` działa.

---

## 6. Pozostało / do pilnowania

- **Klucze API BaseLinker** szyfrowane `APP_KEY` lokalu — jeśli prod ma inny `APP_KEY`, klucze nie odszyfrują się → wpisać od nowa w `/admin/connect/integrations/base`.
- **Scheduler (cron):** dopisać systemowy cron co minutę:
  `* * * * * /usr/local/php83/bin/php /home/admin/domains/pim.bsplate.eu/PIM/artisan schedule:run >/dev/null 2>&1`
  (uruchamia `baselinker:sync-orders` co 5 min, `baselinker:sync-invoices` co 15 min).
- Po stabilizacji rozważyć `config:cache` + `route:cache` dla wydajności (pamiętać o `:clear` przy kolejnych zmianach).

---

## 7. Wnioski na przyszłość

1. **Załóż git** na projekcie — koniec z pakowaniem po dacie modyfikacji.
2. **Prod cofnięty > 1 dzień → pełny sync kodu**, nie różnicowy. Różnicowy gubi starsze zależności (P2/P8).
3. **Front zawsze budować lokalnie** i wgrywać `public/build` (brak npm na prodzie).
4. **Kolejność:** kod → `migrate` → `import danych` → `clear cache` → build (front). Nigdy import data-only przed migracją (P3).
5. **Artisan na prodzie:** zawsze pełna ścieżka `/usr/local/php83/bin/php .../artisan`, plus `printf '\e[?2004l'` na wklejanie.
6. **Pusty sidebar = błąd w konsoli (F12)** — Ziggy zgłasza brakującą trasę po nazwie.
7. **Pamiętać o symlinku** `public_html/build → PIM/public/build` (rozpakowywać build do `PIM/public`).
