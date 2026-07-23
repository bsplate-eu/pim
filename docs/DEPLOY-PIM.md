# Auto-deploy PIM (pim.bsplate.eu)

Ten sam model co sklepy `bsp-{cz,sk,pl,fr,es,lt}` — pełny opis wzorca w `DEPLOY.md`
repozytorium `bsplate-eu/bsp-sk`. Skrót dla PIM:

```
git push  →  cron co 5 min  →  git pull  →  lint PHP (rollback przy błędzie)  →  migracje  →  cache
```

## Różnice względem sklepów

- **`public/build` jest W repozytorium** — serwer nie ma npm, front budujemy lokalnie
  (`npm run build`, Node 18 z `C:\laragon\bin\nodejs\node-v18`) i commitujemy razem ze zmianą.
- **`vendor/` jest POZA repozytorium** — na serwerze leży wgrany ręcznie; po zmianie
  `composer.json`/`composer.lock` trzeba dograć vendor (tar/rsync), deploy tego nie robi.
- **Migracje bazą robi `artisan migrate --force`** (nie własny runner SQL jak w OpenCart).
- Skrypt ma **kontrolę składni zmienionych plików PHP** — parse error = automatyczny
  rollback do poprzedniego commita (route cache na prodzie maskuje błędy składni,
  historycznie kładło to całą stronę po `optimize:clear`).

## Układ na serwerze

- Kod: `/home/admin/domains/pim.bsplate.eu/PIM` (kopia robocza gita, remote `github-pim`)
- Runner: `/home/admin/bin/wdroz-pim.sh` (kopia `deploy/wdroz-pim.sh` z tego repo)
- Cron: `*/5 * * * * /home/admin/bin/wdroz-pim.sh >> /home/admin/domains/pim.bsplate.eu/PIM/storage/logs/deploy.log 2>&1`
- Klucz deploy: `~/.ssh/pim` + alias `github-pim` w `~/.ssh/config`
  (GitHub → repo `pim` → Settings → Deploy keys, read-only)
- Backup sprzed przejścia na gita: `~/pim_kod_przed_git_20260723.tar.gz`

## Czego deploy nie dotyka

`.env`, `storage/`, `public/media`, `public/storage`, vendor, baza (poza `artisan migrate`).

## Pułapki

- Po `git init` na serwerze branch `main` nie ma upstreamu i `git pull --ff-only` pada
  („no tracking information"). Raz: `git branch --set-upstream-to=origin/main main`.
- Skrypt kopiujemy do `/home/admin/bin/` — cron uruchamia kopię, nie plik z repo.
  Po zmianie `deploy/wdroz-pim.sh` trzeba go tam skopiować ponownie.
- `vendor/` nie jedzie gitem: po zmianie zależności deploy przejdzie, ale aplikacja
  może paść na brakującej klasie. Zależności wdrażamy osobno.

## Ręczne wdrożenie / diagnostyka

```bash
ssh admin@5.196.81.23 /home/admin/bin/wdroz-pim.sh          # wymuś teraz
ssh admin@5.196.81.23 tail -50 /home/admin/domains/pim.bsplate.eu/PIM/storage/logs/deploy.log
```

Po zmianach frontu: Ctrl+Shift+R w przeglądarce (cache JS).
