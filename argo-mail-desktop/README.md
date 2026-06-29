# Argo Mail Desktop

Program Windows będący **lustrem** produkcyjnego Argo Mail (poczta w PIM).
Cienka powłoka **Electron**: okno z Argo Mail + ikona w zasobniku + (M1) dymki o nowych mailach.
Cała logika poczty zostaje w webie (panel PIM) — tu nic się nie dubluje.

Pełny opis i decyzje: [`../docs/mail/argo-mail-desktop.md`](../docs/mail/argo-mail-desktop.md)

## Status
✅ okno (lustro produkcji) · ✅ zasobnik · ✅ autostart · ✅ ukryta lewa kolumna PIM · ✅ instalator · ⏳ dymki o nowych mailach (M1)

## Gotowy instalator
Po `npm run dist`:
- `dist/Argo Mail Setup 0.1.0.exe` — **instalator do rozdania** (~20 osobom, 1 plik)
- `dist/win-unpacked/Argo Mail.exe` — wersja przenośna

Niepodpisany → u odbiorcy przy 1. uruchomieniu **SmartScreen**: *Więcej informacji → Uruchom mimo to* (to normalne dla apki firmowej bez płatnego certyfikatu).

## Uruchomienie ze źródeł (dev)
```powershell
cd C:\laragon\www\pim\argo-mail-desktop
npm install
npm start
```

## Konfiguracja (`config.json`)
- `url` — adres Argo Mail. **Produkcja:** `https://panel.argotech.com.pl/admin/argo-mail` (root, **NIE** `/pim/`). Lokalnie: `https://pim.test/admin/argo-mail`.
- `allowInsecureTLS` — `true` tylko dla lokalnego self-signed certu (prod: `false`).
- `autoStart` — start z Windowsem (też w menu zasobnika). Rejestruje się tylko w **zbudowanej** wersji.
- `hideSidebar` — ukrycie lewej kolumny PIM (sama poczta). Domyślnie `true`.
- `poll` — odpytywanie serwera o nowe maile (włączyć po dodaniu endpointu).

## Budowa instalatora (.exe)
```powershell
npm run dist   # electron-builder --win nsis → dist/
```
⚠️ **Pierwszy build na czystej maszynie** potrafi paść na rozpakowaniu `winCodeSign` (macOS symlinki, brak Trybu dewelopera). Obejście **bez admina** — rozpakuj raz, z pominięciem `darwin`, do cache:
```powershell
$bin="node_modules\7zip-bin\win\x64\7za.exe"; $c="$env:LOCALAPPDATA\electron-builder\Cache\winCodeSign"
& $bin x "$c\winCodeSign-2.6.0.7z" "-o$c\winCodeSign-2.6.0" "-xr!darwin" -y
```
…potem powtórz `npm run dist`. (Lub włącz Tryb dewelopera Windows.)

## Zachowanie
- **[X]** chowa okno do zasobnika (program działa dalej). Wyjście: menu zasobnika → *Wyjdź*.
- Jedna instancja — drugie uruchomienie pokazuje istniejące okno.
- Linki „w nowej karcie" → przeglądarka systemowa.
- Lewa kolumna PIM ukryta (sterowane `hideSidebar`).
