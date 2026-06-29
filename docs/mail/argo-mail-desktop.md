# Argo Mail Desktop — program Windows (lustro poczty)

> Status: **Instalator `.exe` gotowy** (lustro produkcji, lewa kolumna ukryta) · zostały **dymki (M1)** · Start: 2026-06-05 · Właściciel: PIM Local
>
> Kod: `argo-mail-desktop/` (w repo PIM). Changelog: `../argo-pim.md`. Spec modułu web: `argo-mail.md`.

Program na pulpit Windows, który jest **lustrem** webowego Argo Mail: to samo okno, ta sama poczta — tylko jako aplikacja w pasku zadań z dymkami o nowych mailach.

---

## 1. Po co (wymagania PM)

1. **Autostart** — rusza z Windowsem (schowany do zasobnika).
2. **Ikona w zasobniku** (obok zegara) — jak Thunderbird/Notion.
3. **Okno wygląda i działa jak Argo Mail** — bo to dosłownie ono.
4. **Dymki w prawym dolnym rogu** o nowych mailach — także gdy okno schowane.
5. **Lustro Argo Mail** — nie osobny klient pocztowy, tylko okno na wspólny serwer.

## 2. Zasada architektury — „cienka powłoka"

```
SERWER PIM (zostaje):  IMAP/SMTP + baza + AI + „kto/do kogo/kategoria"   ← wspólne dla zespołu
        │  (HTTPS, ta sama strona co w przeglądarce)
PROGRAM (nowe):        okno (ładuje URL) + zasobnik + autostart + dymki   ← ~300 linii, prawie się nie zmienia
```

**Dlaczego tak (mądrze + rozwojowo):** cała wartość (poczta) siedzi w webie, który zespół i tak rozwija.
Powłoka jest tak cienka, że każda przyszła zmiana w Argo Mail pojawia się w programie **sama**, bez przebudowy `.exe`.
To też czyni wybór technologii **odwracalnym**.

**Stan na 2026-06-05:** instalator gotowy → `dist/Argo Mail Setup 0.1.0.exe` (78 MB). Program celuje w **PRODUKCJĘ**: `https://panel.argotech.com.pl/admin/argo-mail` — **root, NIE `/pim/`** (sprawdzone: `/pim/admin/argo-mail`→404, `/admin/argo-mail`→302 login; `/pim/` to ścieżka na dysku serwera, nie URL). Lewa kolumna PIM **ukryta** (sama poczta). Zostały **dymki (M1)**.

**Stan na 2026-06-08 — FIX BIAŁEGO OKNA (GPU):** spakowany `Argo Mail.exe` na **sprzętowej akceleracji GPU** malował **okno na biało** — strona ładowała się poprawnie (DOM = formularz logowania), ale kompozytor GPU nie pokazywał treści (klasyczny bug Electron/Windows; potwierdzone zrzutem realnego okna). Naprawione w `src/main.js`:
> - `app.disableHardwareAcceleration()` (sterowane `config.disableGpu`, domyślnie `true`) → rendering programowy, stabilnie na każdej karcie graficznej.
> - okno `show:false` + `backgroundColor:'#0b1b2b'` + pokaz dopiero na **`ready-to-show`** → zero białego błysku przy starcie.
> - handler **`did-fail-load`** → ekran „Brak połączenia" + auto-ponów co 6 s (zamiast bezradnej bieli; ignoruje `-3`/ABORTED z przekierowania na /login).
>
> Wersja `0.1.0 → 0.1.1`, instalator przebudowany → **`dist/Argo Mail Setup 0.1.1.exe`** (~82 MB), stary 0.1.0 usunięty.

## 3. Decyzje techniczne

| Temat | Decyzja | Uzasadnienie |
|---|---|---|
| Framework | **Electron** | Node już jest na maszynie → program gotowy od razu; zespół zna JS (łatwe utrzymanie); mnóstwo przykładów na tray/autostart/dymki. |
| Odrzucone: Tauri | nie teraz | Lżejszy instalator (~10 MB), ale wymaga instalacji **Rust + C++ Build Tools (~kilka GB)** — brak na maszynie. Powłoka jest cienka → przesiadka później jest tania, jeśli waga zacznie przeszkadzać. |
| Odrzucone: PWA | nie | Brak ikony w **zasobniku** (wymóg #2) i słaby autostart/tło. |
| Odrzucone: natywny klient (C#) | nie | Pełne przepisanie, rozbija model „wspólnego lustra". |
| Źródło prawdy | **serwer** | Program tylko pyta serwer; nie łączy się sam po IMAP (inaczej każdy miałby swoją wyspę). |

## 4. Jak realizujemy każdy wymóg

| # | Wymóg | Mechanizm (w `src/main.js`) |
|---|---|---|
| 1 | Autostart | `app.setLoginItemSettings({openAtLogin:true, args:['--hidden']})`; przy autostarcie okno startuje schowane. Przełącznik też w menu zasobnika. |
| 2 | Zasobnik | `Tray` + menu (Otwórz / Odśwież / Autostart / Wyjdź). Lewy klik = pokaż/schowaj. |
| 3 | Okno = Argo Mail | `BrowserWindow.loadURL(config.url)`, `autoHideMenuBar` (bez paska przeglądarki). |
| 4 | Dymki o mailach | `Notification` (natywny toast Windows, prawy dół). Dwie drogi — patrz niżej. Klik w dymek → okno + konkretny mail. |
| 5 | Lustro | Ładuje żywy URL panelu; sesja (logowanie) trzyma się między uruchomieniami. |

**Dodatkowo:** **ukryta lewa kolumna PIM** (`insertCSS` na `dom-ready` chowa sidebar `[class*="md:fixed"][class*="md:inset-y-0"]` + zeruje `md:pl-64/20` → sama poczta; opcja `hideSidebar`; tylko w programie, produkcja w przeglądarce bez zmian), [X] chowa do zasobnika (nie zamyka), jedna instancja (single-instance lock), linki „w nowej karcie" → przeglądarka systemowa, self-signed cert lokalnego `pim.test` akceptowany tylko w dev.

## 5. Powiadomienia — dwie drogi (obie wpięte)

**A) Z frontu (zalecane — zero pracy na serwerze).**
Powłoka wystawia stronie `window.argoDesktop.notify({from, subject, id})` (preload).
Gdy web Argo Mail wykryje nowy mail (istniejący `NotificationBell` / poll listy), woła tę funkcję → natywny dymek.
W zwykłej przeglądarce `window.argoDesktop` jest `undefined` → kod nic nie robi (bezpieczne).

**B) Z powłoki (fallback).**
Program co `intervalSeconds` pyta endpoint `poll.endpoint` przez `net.fetch` (dzieli ciasteczka z oknem → zapytanie zalogowane).
Endpoint zwraca np. `{ "items": [ {"id":123,"from":"X","subject":"Y"} ] }`. Włączane w `config.json` po dodaniu endpointu.

> ⚠️ **Opóźnienie:** poczta jest pobierana na serwerze (cron co 1 min). Dymek pojawi się realnie **~1–2 min** od przyjścia maila — nie „co do sekundy". Dla poczty firmowej wystarcza.

## 6. Pliki

```
argo-mail-desktop/
  package.json        # zależności + konfiguracja electron-builder (instalator NSIS, icon=png)
  config.json         # url, allowInsecureTLS, disableGpu, autostart, hideSidebar, poll
  src/main.js         # proces główny: okno, zasobnik, autostart, ukrycie kolumny (insertCSS), dymki, poll, disableHardwareAcceleration
  src/preload.js      # most window.argoDesktop (notify / isDesktop / version)
  assets/icon.png     # ikona (reuse public/icons/argo-512.png) — electron-builder robi z niej .ico
  README.md
  dist/               # WYNIK builda (gitignore): Argo Mail Setup 0.1.1.exe + win-unpacked/Argo Mail.exe
```

## 7. Status i plan

- [x] Powłoka: okno + zasobnik + close-to-tray + autostart + single-instance (`main.js`)
- [x] Most `window.argoDesktop.notify` + szkielet pollingu (`net.fetch`)
- [x] Reuse ikony Argo
- [x] `npm install` + uruchomienie na żywo (potwierdzone: okno, zasobnik, autostart)
- [x] Cel = **produkcja** (URL root) + sesja trzyma się między uruchomieniami
- [x] **Ukryta lewa kolumna PIM** (insertCSS) → sama poczta
- [x] `npm run dist` → **instalator** `dist/Argo Mail Setup 0.1.1.exe` (~82 MB) + `win-unpacked/Argo Mail.exe` (ikona Argo)
- [x] **FIX białego okna (GPU)** 2026-06-08 → `disableHardwareAcceleration` + `ready-to-show` (bez błysku) + ekran „Brak połączenia"; wersja **0.1.1**
- [x] Obejście błędu builda **winCodeSign** (symlinki) — patrz `feedback` w pamięci
- [ ] **M1 — Powiadomienia (dymki):** droga A = hook w web Argo Mail (`NotificationBell`/poll) wołający `window.argoDesktop?.notify(...)`; lub droga B = endpoint `GET /admin/argo-mail/desktop/poll`
- [ ] Klik w dymek → otwarcie konkretnego maila (kod jest, test end-to-end po M1)
- [ ] Po M1 → **przebudowa instalatora** i dystrybucja do zespołu (~20 osób, 1 plik)
- [ ] (później) podpis cyfrowy (usuwa SmartScreen) + auto-update (electron-updater)

## 8. Uwagi rozwojowe

- Powłoka jest **generyczna**: zmiana `config.url` robi z niej lustro dowolnego modułu PIM. Dziś poczta; jutro można dorzucić TASK (most `notify` już jest).
- Nie wrzucać `node_modules/` ani `dist/` do gita (jest `.gitignore`).
- Build instalatora robimy **lokalnie** (jak front PIM) — VPS nie jest do tego potrzebny.
