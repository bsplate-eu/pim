// Argo Mail Desktop — cienka powłoka (Electron) na webowy Argo Mail.
// Zasada: TU jest tylko okno + zasobnik + powiadomienia. Cała poczta = web (panel PIM).
// Dzieki temu kazda zmiana w Argo Mail pojawia sie w programie sama, bez przebudowy.

const { app, BrowserWindow, Tray, Menu, Notification, shell, nativeImage, ipcMain, net } = require('electron');
const path = require('path');
const fs = require('fs');

// --- Konfiguracja (rozwojowo: wszystko sterowane plikiem config.json obok pliku .exe) ---
function loadConfig() {
  const defaults = {
    appName: 'Argo Mail',
    url: 'https://pim.test/admin/argo-mail',   // lokalny Laragon; PROD -> patrz config.json
    allowInsecureTLS: true,                      // self-signed cert lokalnego pim.test (PROD: false)
    disableGpu: true,                            // wylacz akceleracje GPU (lek na BIALE OKNO na nietypowych kartach)
    startMinimized: false,                       // przy autostarcie i tak startuje schowany (--hidden)
    autoStart: true,
    hideSidebar: true,                           // ukryj lewa kolumne nawigacji PIM (program = sama poczta)
    hideTopbar: true,                            // ukryj gorny pasek panelu (zwijanie menu + dzwonek) — w programie zbedny
    poll: {
      enabled: false,                            // wlaczyc po dodaniu endpointu na serwerze
      endpoint: '/admin/argo-mail/desktop/poll',
      intervalSeconds: 60
    }
  };
  try {
    const p = path.join(__dirname, '..', 'config.json');
    if (fs.existsSync(p)) return deepMerge(defaults, JSON.parse(fs.readFileSync(p, 'utf8')));
  } catch (e) {
    console.error('[config.json] blad odczytu, uzywam domyslnych:', e.message);
  }
  return defaults;
}
function deepMerge(a, b) {
  const out = Object.assign({}, a);
  for (const k of Object.keys(b || {})) {
    out[k] = (b[k] && typeof b[k] === 'object' && !Array.isArray(b[k])) ? deepMerge(a[k] || {}, b[k]) : b[k];
  }
  return out;
}

const CONFIG = loadConfig();
const ICON = path.join(__dirname, '..', 'assets', 'icon.png');

// GPU: na czesci kart graficznych (Windows) sprzetowa kompozycja maluje OKNO NA BIALO —
// tresc sie laduje, ale uzytkownik widzi biel. Rendering programowy to naprawia raz na zawsze.
// Domyslnie ON, bo apka jedzie na rozne, nieznane komputery (config "disableGpu": false => GPU z powrotem).
if (CONFIG.disableGpu !== false) app.disableHardwareAcceleration();

// CSS wstrzykiwany do strony: chowa lewa kolumne nawigacji PIM i daje poczcie pelna szerokosc.
// Selektory atrybutowe [class*="..."] celuja w klasy Tailwind layoutu (Authenticated.vue) —
// odporne na escaping ":" i na stan zwiniecia (md:pl-64 / md:pl-20). Produkcja w przegladarce bez zmian.
const HIDE_SIDEBAR_CSS = `
[class*="md:fixed"][class*="md:inset-y-0"] { display: none !important; }
[class*="md:pl-64"], [class*="md:pl-20"], [class*="md:pl-16"] { padding-left: 0 !important; }
`;

// Gorny pasek panelu (hamburger + zwijanie menu + dzwonek) z Authenticated.vue.
// Kombinacja klas jest unikalna dla tej belki (nagłówki poczty/mobile maja z-10/z-30, bez justify-between).
const HIDE_TOPBAR_CSS = `
[class*="sticky"][class*="top-0"][class*="z-20"][class*="justify-between"][class*="border-b"] { display: none !important; }
`;

// Ekran zastepczy gdy serwer/internet nie odpowiada — zamiast bezradnej bieli.
function errorPageHTML(detail) {
  const safe = String(detail || '').replace(/[<>&]/g, '');
  return `<!doctype html><html lang="pl"><head><meta charset="utf-8"><style>
    html,body{height:100%;margin:0}
    body{background:#0b1b2b;color:#e6edf6;font-family:'Segoe UI',Arial,sans-serif;display:flex;align-items:center;justify-content:center}
    .box{text-align:center;max-width:440px;padding:24px}
    .box h1{font-size:20px;margin:0 0 10px}
    .box p{opacity:.82;line-height:1.55;margin:6px 0}
    .s{font-size:12px;opacity:.45;margin-top:16px}
  </style></head><body><div class="box">
    <h1>Brak połączenia z Argo Mail</h1>
    <p>Nie udało się wczytać poczty z serwera. Sprawdź internet lub VPN i poczekaj — ponawiam automatycznie za chwilę.</p>
    <div class="s">${safe}</div>
  </div></body></html>`;
}

let mainWindow = null;
let tray = null;
app.isQuitting = false;

// Wazne dla Windows: zeby dymki pokazywaly nazwe "Argo Mail", a nie "electron.app..."
app.setAppUserModelId('com.argo.mail.desktop');

// Tylko jedna instancja — drugie uruchomienie pokazuje istniejace okno
if (!app.requestSingleInstanceLock()) {
  app.quit();
} else {
  app.on('second-instance', () => showWindow());

  app.whenReady().then(() => {
    // Autostart rejestrujemy TYLKO w zbudowanym programie (.exe). W wersji "ze zrodla"
    // execPath to electron.exe i wpis bylby wadliwy — wiec pomijamy, by nie smiecic w rejestrze.
    if (CONFIG.autoStart && app.isPackaged) setAutoStart(true);
    createWindow();
    createTray();
    if (CONFIG.poll && CONFIG.poll.enabled) startPolling();
  });
}

function createWindow() {
  mainWindow = new BrowserWindow({
    width: 1280,
    height: 860,
    minWidth: 900,
    minHeight: 600,
    title: CONFIG.appName,
    icon: ICON,
    autoHideMenuBar: true,                 // bez paska menu File/Edit — ma wygladac jak appka, nie przegladarka
    show: false,                           // pokazujemy DOPIERO gdy strona gotowa (ready-to-show) => zero bialego blysku
    backgroundColor: '#0b1b2b',            // granat ARGO zamiast bialego tla na czas ladowania
    webPreferences: {
      preload: path.join(__dirname, 'preload.js'),
      contextIsolation: true,
      nodeIntegration: false,
      spellcheck: true
    }
  });

  // Lokalny self-signed cert (Laragon). PROD ma prawdziwy cert => allowInsecureTLS=false i tu nie wchodzi.
  if (CONFIG.allowInsecureTLS) {
    mainWindow.webContents.session.setCertificateVerifyProc((req, cb) => cb(0));
  }

  mainWindow.loadURL(CONFIG.url);

  // Pokaz okno dopiero gdy tresc jest gotowa (inaczej w trakcie ladowania strony z serwera widac biale okno).
  mainWindow.once('ready-to-show', () => {
    if (!(CONFIG.startMinimized || process.argv.includes('--hidden'))) mainWindow.show();
  });

  // Brak internetu / serwer nie odpowiada -> zamiast bialego okna pokaz komunikat i ponow automatycznie za 6 s.
  mainWindow.webContents.on('did-fail-load', (e, code, desc, url, isMainFrame) => {
    if (!isMainFrame || code === -3) return;   // -3 = przerwane (np. przekierowanie do /login) — to normalne, ignoruj
    mainWindow.loadURL('data:text/html;charset=utf-8,' + encodeURIComponent(errorPageHTML(desc)));
    if (!mainWindow.isVisible()) mainWindow.show();
    setTimeout(() => { if (mainWindow && !mainWindow.isDestroyed()) mainWindow.loadURL(CONFIG.url); }, 6000);
  });

  // Linki "otworz w nowej karcie" (np. klik w adres firmy) -> przegladarka systemowa, nie nowe okno Electrona
  mainWindow.webContents.setWindowOpenHandler(({ url }) => {
    shell.openExternal(url);
    return { action: 'deny' };
  });

  // Ukryj chrome panelu PIM (re-inject po kazdym pelnym przeladowaniu; przy nawigacji SPA CSS i tak zostaje)
  if (CONFIG.hideSidebar || CONFIG.hideTopbar) {
    mainWindow.webContents.on('dom-ready', () => {
      let css = '';
      if (CONFIG.hideSidebar) css += HIDE_SIDEBAR_CSS;
      if (CONFIG.hideTopbar) css += HIDE_TOPBAR_CSS;
      mainWindow.webContents.insertCSS(css).catch(() => {});
    });
  }

  // Klikniecie [X] = schowaj do zasobnika (program dalej dziala i powiadamia). Wyjscie tylko z menu tray.
  mainWindow.on('close', (e) => {
    if (!app.isQuitting) {
      e.preventDefault();
      mainWindow.hide();
    }
  });
}

function showWindow() {
  if (!mainWindow || mainWindow.isDestroyed()) createWindow();
  if (mainWindow.isMinimized()) mainWindow.restore();
  mainWindow.show();
  mainWindow.focus();
}

function createTray() {
  const img = nativeImage.createFromPath(ICON).resize({ width: 16, height: 16 });
  tray = new Tray(img.isEmpty() ? ICON : img);
  tray.setToolTip(CONFIG.appName);
  rebuildTrayMenu();
  // Lewy klik na ikonie: pokaz/schowaj okno
  tray.on('click', () => {
    if (mainWindow && mainWindow.isVisible() && !mainWindow.isMinimized()) mainWindow.hide();
    else showWindow();
  });
}

function rebuildTrayMenu() {
  const menu = Menu.buildFromTemplate([
    { label: 'Otworz ' + CONFIG.appName, click: () => showWindow() },
    { label: 'Odswiez', click: () => mainWindow && mainWindow.webContents.reload() },
    { type: 'separator' },
    {
      label: 'Uruchamiaj przy starcie Windows',
      type: 'checkbox',
      checked: app.getLoginItemSettings().openAtLogin,
      click: (item) => setAutoStart(item.checked)
    },
    { type: 'separator' },
    { label: 'Wyjdz', click: () => { app.isQuitting = true; app.quit(); } }
  ]);
  tray.setContextMenu(menu);
}

function setAutoStart(enabled) {
  // Przy autostarcie program rusza schowany do zasobnika (--hidden)
  const settings = { openAtLogin: enabled };
  if (!app.isPackaged) {
    // Wersja "ze zrodla": electron.exe musi dostac sciezke projektu, inaczej
    // autostart odpalilby pusty Electron. (Toggle z menu zasobnika dziala wtedy poprawnie.)
    settings.path = process.execPath;
    settings.args = [path.resolve(__dirname, '..'), '--hidden'];
  } else {
    settings.args = ['--hidden'];
  }
  app.setLoginItemSettings(settings);
  if (tray) rebuildTrayMenu();
}

// --- Powiadomienia o nowych mailach ---------------------------------------
// Dwie drogi (obie wpiete):
//  A) Web Argo Mail wykrywa nowy mail i wola window.argoDesktop.notify(...) -> natywny dymek (zero pracy na serwerze).
//  B) Powloka sama pyta endpoint serwera (ponizej) co X sekund. Wlaczane w config.json gdy endpoint powstanie.

let lastSeenId = 0;

function startPolling() {
  const tick = async () => {
    try {
      const base = new URL(CONFIG.url).origin;
      // net.fetch dzieli ciasteczka z oknem (ta sama sesja) -> zapytanie jest zalogowane
      const res = await net.fetch(base + CONFIG.poll.endpoint, { headers: { Accept: 'application/json' } });
      if (!res.ok) return;                       // endpoint jeszcze nie istnieje / niezalogowany -> cicho
      const data = await res.json();
      const items = Array.isArray(data.items) ? data.items : [];
      const fresh = items.filter((m) => Number(m.id) > lastSeenId);
      if (lastSeenId > 0) fresh.slice(0, 5).forEach(notifyNewMail);   // pierwszy obieg = tylko ustaw baze, bez spamu
      if (items.length) lastSeenId = Math.max(lastSeenId, ...items.map((m) => Number(m.id) || 0));
    } catch (e) {
      /* offline / niezalogowany -> cisza, sprobujemy za chwile */
    }
  };
  tick();
  setInterval(tick, Math.max(15, Number(CONFIG.poll.intervalSeconds) || 60) * 1000);
}

function notifyNewMail(m) {
  if (!Notification.isSupported()) return;
  const n = new Notification({
    title: 'Nowy mail: ' + (m.from || m.title || ''),
    body: m.subject || m.body || '(bez tematu)',
    icon: ICON,
    silent: false
  });
  n.on('click', () => {
    showWindow();
    if (m.id) mainWindow.loadURL(new URL(CONFIG.url).origin + '/admin/argo-mail/messages/' + m.id);
  });
  n.show();
}

// Most dla strony webowej (preload wystawia window.argoDesktop)
ipcMain.on('notify', (_e, payload = {}) => notifyNewMail(payload));
ipcMain.handle('app:version', () => app.getVersion());

// macOS-friendly (gdyby kiedys), na Windows bez znaczenia
app.on('window-all-closed', () => { /* nie wychodzimy — zyjemy w zasobniku */ });
app.on('activate', () => showWindow());
