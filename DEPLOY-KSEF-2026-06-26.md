# Deploy KSeF na produkcję — runbook (2026-06-26)

Wdrożenie modułu **KSeF** (Argo HQ → Ksef + Argo Connect → Integracje · KSeF) na `pim.bsplate.eu`.
Addytywne — dokłada moduł KSeF, **nie rusza** istniejącej roboty na prodzie.

> Dane FV **NIE są przenoszone** — zaciągniesz je na prodzie sam (token + „Zaciągnij wszystko").

## Dwa pliki
| Plik | Co z nim |
|---|---|
| `_deploy_ksef_2026-06-26.zip` (17 MB) | pliki na serwer — rozpakować w `PIM/` |
| `_deploy_ksef_2026-06-26.sql` | import w **phpMyAdmin** na bazie `admin_pim` (tworzy 3 tabele KSeF + kategorie + rejestruje migracje) |

ZIP zawiera: kod KSeF (kontrolery/modele/serwisy), `routes/crafter.php`, 5 migracji, KSeF Vue + Sidebar + Ebay, `composer.json/lock`, **cały `public/build`** oraz **vendor delta** (SDK `n1ebieski/ksef-php-client` + zależności: endroid, bacon, cuyz/valinor, krowinski, psr-discovery, **phpseclib 3.0.46→3.0.55**, paragonie/constant_time_encoding + `vendor/composer` autoloader).

Prod root: `/home/admin/domains/pim.bsplate.eu/PIM` · PHP: `/usr/local/php83/bin/php`

---

## Krok 0 — BACKUP (obowiązkowo)
- Baza: phpMyAdmin → Eksport bazy `admin_pim`.
- Kod (nadpisujemy phpseclib, autoloader, build):
```bash
printf '\e[?2004l'
cd /home/admin/domains/pim.bsplate.eu/PIM
tar czf ~/PIM_backup_ksef_$(date +%F).tar.gz routes/crafter.php vendor/composer vendor/phpseclib vendor/paragonie/constant_time_encoding public/build resources composer.json composer.lock 2>/dev/null
```

## Krok 1 — Baza (phpMyAdmin)
phpMyAdmin → baza `admin_pim` → **Import** → `_deploy_ksef_2026-06-26.sql` → Wykonaj.
(Alternatywnie, jeśli masz CLI: `/usr/local/php83/bin/php artisan migrate --force` — zamiast SQL.)

## Krok 2 — Pliki na serwer
Wgraj `_deploy_ksef_2026-06-26.zip` przez DirectAdmin **File Manager** do `PIM/` (NIE do `public_html`), potem:
```bash
printf '\e[?2004l'
cd /home/admin/domains/pim.bsplate.eu/PIM
unzip -o _deploy_ksef_2026-06-26.zip
rm _deploy_ksef_2026-06-26.zip
ls public/build/manifest.json     # musi istnieć
```
> `public/build` rozpakuje się do `PIM/public/build` — symlink z `public_html/build` zadziała.

## Krok 3 — Czyszczenie cache
```bash
P=/usr/local/php83/bin/php
A=/home/admin/domains/pim.bsplate.eu/PIM/artisan
$P $A route:clear
$P $A config:clear
$P $A view:clear
$P $A cache:clear
```
> Jeśli używasz `route:cache`/`config:cache` — zrób je ponownie po `:clear`.

## Krok 4 — Poświadczenia KSeF (KRYTYCZNE)
Tokeny z lokalu szyfrowane lokalnym `APP_KEY` → na prodzie się NIE odszyfrują. Tabela `ksef_settings` jest pusta.
Panel → **Argo Connect → Integracje · KSEF** → wpisz dla obu firm:
- **Pareto** — NIP `9252014791`, środowisko **produkcja**, token KSeF
- **BSP** — NIP `9252152027`, środowisko **produkcja**, token KSeF
→ Zapisz.

## Krok 5 — Weryfikacja
```bash
$P $A route:list | grep -i ksef        # ksef.pareto/bsp/import/pdf/categories + connect.integrations.ksef
$P $A migrate:status | grep -i ksef    # 5 migracji = Ran (jeśli importowałeś SQL — też pokaże Ran)
```
Panel (`Ctrl+Shift+R`): **Argo HQ → Ksef → Ksef Pareto / Ksef BSP** otwiera się; **Argo Connect → Integracje · KSEF** ma 2 taby.

## Krok 6 — Zaciągnięcie FV (robisz sam)
Na każdej firmie: „Import faktur" → zakres → **„Zaciągnij wszystko"**. Eksport zbiorczy KSeF → realne pozycje + terminy + PDF z bazy.

---

## Rollback / problemy
- **Pusty sidebar** = brakująca trasa → F12 konsola pokaże którą; sprawdź czy `routes/crafter.php` się rozpakował + `route:clear`.
- **„Class not found" (n1ebieski / phpseclib)** = vendor niepełny / stary autoloader → ponów `unzip -o`, potem `$P $A clear-compiled` i `$P $A optimize:clear`.
- **Wycofanie kodu:** `cd PIM && tar xzf ~/PIM_backup_ksef_<data>.tar.gz` + `route:clear config:clear`. Tabele KSeF można zostawić (puste, nieszkodliwe) albo `DROP TABLE ksef_invoices, ksef_settings, ksef_categories;`.
- **KSeF „429 Too Many Requests"** przy imporcie = limit; powtórz za chwilę.
