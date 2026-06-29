# Argo Mail — architektura techniczna

> Aktualizacja: 2026-06-01 · Powiązane: `argo-mail.md` (spec), `funkcje-mail.md` (UI), `argo-mail-desktop.md` (program Windows)
>
> 🖥️ **Warstwa desktop:** cienka powłoka **Electron** (`argo-mail-desktop/`) ładuje ten sam web po HTTPS — nie dubluje architektury (brak własnej bazy/IMAP). Dokłada tylko: zasobnik, autostart, natywne dymki (`window.argoDesktop.notify` / poll). Szczegóły: `argo-mail-desktop.md`.

---

## 1. Ogólny schemat przepływu danych

```
┌─────────────────────────────────────────────────────────────────┐
│  ZEWNĘTRZNE SKRZYNKI (Gmail, itp.)                              │
│  Poczta fizycznie siedzi na serwerach dostawców.                │
└──────────────┬──────────────────────────┬───────────────────────┘
               │ IMAP (odbiór)            │ SMTP (wysyłka)
               ▼                          ▼
┌─────────────────────────────────────────────────────────────────┐
│  BACKEND — Laravel 10                                           │
│                                                                 │
│  MailSyncService ──► mail_messages (DB)                         │
│  MailController  ◄── Inertia / Axios (JSON)                     │
│  Scheduler / BAT ──► mail:sync (co minutę)                      │
└──────────────────────────────┬──────────────────────────────────┘
                               │ Inertia props / JSON
                               ▼
┌─────────────────────────────────────────────────────────────────┐
│  FRONTEND — Vue 3 + Inertia + Tailwind                          │
│                                                                 │
│  ArgoMail/Index.vue  (panel główny)                             │
│  ArgoMail/Settings.vue                                          │
│  ArgoMail/Accounts/Form.vue                                     │
└─────────────────────────────────────────────────────────────────┘
```

---

## 2. Modele i tabele bazy danych

```
mail_accounts          (skrzynki e-mail)
  id, label, email
  imap_host, imap_port, imap_encryption
  smtp_host, smtp_port, smtp_encryption
  username, password [encrypted], oauth_token [encrypted]
  signature, color
  sync_status, sync_error, last_sync_at
  sync_window_months, is_active
        │ hasMany
        ▼
mail_folders           (foldery IMAP pobrane lokalnie)
  id, account_id, name, path
  last_uid, messages_count, unread_count, last_synced_at
        │ hasMany
        ▼
mail_messages          (pobrane maile — lokalny cache)
  id, account_id, folder_id
  uid, message_id, in_reply_to
  from_email, from_name
  to_recipients [JSON], cc_recipients [JSON]
  subject, date, snippet
  body_html, body_text
  has_attachments, size
  is_read, is_flagged
  is_trashed, trashed_at
  is_sent, is_spam
  color_flag            ← red|green|blue|orange|null
  category_id → mail_categories
  catalog_id  → mail_catalogs
  assigned_admin_user_id → admin_users
  categorized_by        ← ai|manual|rule
        │ hasMany
        ▼
mail_attachments       (metadane załączników — treść z IMAP na żądanie)
  id, message_id, part_index, filename, mime, size

mail_categories        (etykiety AI)
  id, name, color, is_system, sort

mail_catalogs          (drzewo folderów użytkownika)
  id, name, parent_id, color, sort
  ← drzewo adjacency list, depth liczony na backendzie

mail_users             (osoby obsługujące pocztę)
  id, admin_user_id → admin_users, color, sort

mail_sender_rules      (auto-reguły: nadawca → osoba/katalog)
  id, from_email [unique], assigned_admin_user_id, catalog_id

mail_spam_senders      (czarna lista nadawców)
  id, from_email [unique, lowercase]
```

---

## 3. Backend — pliki i odpowiedzialności

### Kontrolery

```
App\Http\Controllers\Admin\Mail\
│
├── MailController.php          ← GŁÓWNY kontroler panelu
│   │
│   ├── index()                 Inertia: panel główny
│   │   ├── buduje $filters (account/user/category/catalog/unread/trash/spam/color/q)
│   │   ├── buduje $query z filtrami na mail_messages
│   │   ├── (clone $query) → colorCounts (group by color_flag)
│   │   ├── liczy unprzeczytane: accUnread, userUnread, catUnread
│   │   ├── liczy: totalUnread, trashUnread, trashTotal, spamUnread, spamTotal
│   │   └── zwraca props do Index.vue
│   │
│   ├── settings()              Inertia: strona Ustawienia
│   │   └── zwraca: catalogs(tree), categories, users, availableUsers, spamSenders
│   │
│   ├── showMessage(Message)    JSON: pełna wiadomość + mark as read
│   ├── assignUser()            JSON: przypisz osobę (+ opcjonalna SenderRule)
│   ├── assignCategory()        JSON: przypisz kategorię
│   ├── assignCatalog()         JSON: przypisz katalog
│   ├── fileSenderToCatalog()   JSON: drag&drop → reguła nadawcy + masowe przeniesienie
│   │   ├── catalog_id != null → upsert SenderRule + update wszystkich maili nadawcy
│   │   └── catalog_id = null  → kasuj regułę + zdejmij katalog (upuszczono na "Wszystkie")
│   ├── trashMessage()          JSON: do kosza / przywróć
│   ├── markSpam()              JSON: nadawca → spam (SpamSender + masowe is_spam=true)
│   ├── unspamMessage()         JSON: usuń z SpamSender + is_spam=false
│   ├── storeSpamSender()       POST form: ręczne dodanie do listy spamu (Ustawienia)
│   ├── destroySpamSender()     DELETE: usuń z listy spamu + przywróć maile
│   ├── bulk()                  JSON: masowe akcje (trash/restore/read/unread/category/catalog/user)
│   ├── setColor()              JSON: kolor_flag na zaznaczonych mailach
│   ├── send()                  JSON: wysyłka SMTP + kopia do katalogu SEND
│   │   └── odbiera FormData (+ attachments[] jako pliki)
│   ├── syncAccount()           JSON: ręczna synchronizacja skrzynki
│   ├── downloadAttachment()    Response: pobiera treść załącznika z IMAP na żądanie
│   └── private:
│       ├── catalogTree()       buduje drzewo katalogów z licznikami
│       ├── sendCatalogId()     upsert katalogu SEND/[skrzynka]
│       ├── parseEmails()       parsuje ciąg "a@b.com, c@d.com"
│       └── userName()
│
├── AccountController.php       CRUD skrzynek + test połączenia
│   ├── test()                  próbuje IMAP i SMTP bez zapisu
│   ├── store() / update()      normalizuje App Password (usuwa spacje)
│   └── sync → MailController@syncAccount
│
├── CatalogController.php       CRUD katalogów (store/update/destroy)
└── MailUserController.php      CRUD osób obsługujących pocztę
```

### Serwisy

```
App\Services\Mail\
│
├── MailSyncService.php         GŁÓWNY SERWIS SYNCHRONIZACJI
│   └── sync(Account, cap=200)
│       ├── łączy się przez webklex/laravel-imap → Client::make(imapConfig())
│       ├── iteruje WSZYSTKIE zwykłe foldery (od 03.06; INBOX + własne; bez Wysłane/Kosz/Spam/[Gmail])
│       ├── pierwsze połączenie folderu: since(now()->subMonths(N))
│       ├── przyrostowe:         since(now()->subDays(3))
│       ├── iteruje wiadomości → Message::updateOrCreate([uid])
│       ├── stosuje SenderRule (auto-przypisanie osoby/katalogu)
│       ├── stosuje SpamSender (is_spam = true dla nadawców z listy)
│       └── metadane załączników → mail_attachments (bez treści)
│
└── MailAiCategorizer.php       Kategoryzacja AI (OpenAI)
    ├── pobiera nieskategoryzowane maile (batch 25)
    ├── buduje prompt z listą kategorii
    ├── POST → api.openai.com/v1/chat/completions
    └── zapisuje category_id + categorized_by='ai'
```

### Komendy i scheduler

```
App\Console\Commands\MailSync.php
└── mail:sync [--account=ID]
    └── foreach aktywnych kont → MailSyncService::sync()

App\Console\Kernel.php
└── $schedule->command('mail:sync')
        ->everyMinute()
        ->withoutOverlapping()
        ->name('argo_mail_sync');

Lokalnie (brak crona):
  mail-sync-loop.bat → pętla: php artisan mail:sync + timeout 60s

Prod (DirectAdmin VPS):
  * * * * * php artisan schedule:run
```

---

## 4. Trasy (routes/crafter.php)

```
GET    argo-mail                               → index()           → Index.vue
GET    argo-mail/settings                      → settings()        → Settings.vue

GET    argo-mail/accounts                      → AccountController@index
GET    argo-mail/accounts/create               → create()
POST   argo-mail/accounts                      → store()
GET    argo-mail/accounts/{account}/edit       → edit()
PUT    argo-mail/accounts/{account}            → update()
DELETE argo-mail/accounts/{account}            → destroy()
POST   argo-mail/accounts/test                 → test()
POST   argo-mail/accounts/{account}/sync       → MailController@syncAccount

POST   argo-mail/catalogs                      → CatalogController@store
PUT    argo-mail/catalogs/{catalog}            → update()
DELETE argo-mail/catalogs/{catalog}            → destroy()

POST   argo-mail/mail-users                    → MailUserController@store
PUT    argo-mail/mail-users/{mailUser}         → update()
DELETE argo-mail/mail-users/{mailUser}         → destroy()

GET    argo-mail/messages/{message}            → showMessage()     JSON
GET    argo-mail/messages/{message}/attachments/{att} → downloadAttachment()

POST   argo-mail/messages/{message}/catalog    → assignCatalog()   JSON
POST   argo-mail/messages/{message}/file-sender → fileSenderToCatalog() JSON ← drag&drop
POST   argo-mail/messages/{message}/user       → assignUser()      JSON
POST   argo-mail/messages/{message}/category   → assignCategory()  JSON
POST   argo-mail/messages/{message}/trash      → trashMessage()    JSON
POST   argo-mail/messages/{message}/spam       → markSpam()        JSON
POST   argo-mail/messages/{message}/unspam     → unspamMessage()   JSON
POST   argo-mail/messages/bulk                 → bulk()            JSON
POST   argo-mail/messages/color                → setColor()        JSON

POST   argo-mail/spam                          → storeSpamSender()
DELETE argo-mail/spam/{spamSender}             → destroySpamSender()

POST   argo-mail/send                          → send()            JSON (FormData)
```

---

## 5. Frontend — przepływ danych

### Inertia (pełne przeładowanie strony)

```
Przeglądarka → route('argo-mail.index', {filters})
    ↓
MailController@index()
    ↓ props
Index.vue (defineProps)
    accounts[], users[], categories[], catalogs[]
    messages{data[], meta, links}
    filters{account_id, user_id, category_id, catalog_id,
            unread, trash, spam, color, q}
    totalUnread, trashUnread, trashTotal
    spamUnread, spamTotal, colorCounts{}
```

### Axios (bez przeładowania strony, JSON)

```
[Klik w mail]
    → axios.get('argo-mail/messages/{id}')
    → selected.value = data        ← reaktywny ref
    → iframe.srcdoc = bodyDoc      ← computed z base target="_blank"

[Klik filtru / taba]
    → navigate({...overrides})
    → router.get(route, params, {preserveState, replace})
    → Inertia partial reload (tylko zmienione props)

[Klik akcji — przypisanie/kolor/kosz/spam]
    → axios.post(endpoint, payload)
    → toast.success()
    → reloadAll()
       └── router.reload({only:[messages, accounts, ..., colorCounts]})

[Drag & drop na katalog]
    → axios.post('messages/{id}/file-sender', {catalog_id})
    → backend: SenderRule upsert + Message::update(wszystkie maile nadawcy)
    → toast + reloadAll()

[Wysyłka maila]
    → FormData (z plikami attachments[])
    → axios.post('argo-mail/send', fd)
    → backend: EsmtpTransport → attachFromPath() → $email->send()
    → kopia → mail_messages (is_sent=true, catalog_id=SEND/[skrzynka])

[Auto-odświeżanie co 60s]
    → window.setInterval → router.reload({only:[messages, ...]})
```

---

## 6. Wysyłka SMTP — szczegóły

```
Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport
  $host = account.smtp_host
  $port = account.smtp_port
  $tls  = (encryption === 'ssl')      ← 465/SSL=true, 587/STARTTLS=false

Symfony\Component\Mime\Email
  ->from(new Address(account.email, account.label))
  ->addTo(), ->addCc()
  ->subject(), ->text(), ->html()
  ->getHeaders()->addTextHeader('In-Reply-To', ...)
  ->attachFromPath(file->getRealPath(), name, mime)   ← załączniki

Gmail: SMTP auto-zapisuje wysłane do folderu Sent na Gmailu.
PIM:   osobna kopia lokalnie → mail_messages (is_sent=true, folder __SENT_LOCAL)
       przypisana do katalogu SEND/[label skrzynki] (auto-tworzony).
```

---

## 7. Odbiór IMAP — szczegóły

```
webklex/laravel-imap ^6.2  (bez ext-imap, pure PHP)

Client::make(account->imapConfig())   ← host/port/encryption/username/password
  ->connect()
  ->getFolders(false)              ← od 03.06: pętla po WSZYSTKICH zwykłych folderach (bez Wysłane/Kosz/Spam/[Gmail])
  // (poniższy łańcuch wykonywany per folder)
  ->messages()
     ->setFetchBody(true)
     ->setFetchOrder('desc')
     ->limit(200)
     ->since(now()->subDays(3))       ← przyrostowo (po pierwszym syncu)
     ->get()

Każda wiadomość → Message::updateOrCreate(
  [account_id, folder_id, uid],        ← klucz unikalności
  [subject, from_email, body_html, body_text, is_read, is_spam, ...]
)

Kolejność przetwarzania nowego maila:
  1. updateOrCreate → jeśli nowy (wasRecentlyCreated):
  2. SpamSender::has(from_email) → is_spam = true
  3. SenderRule::has(from_email) → catalog_id / assigned_admin_user_id
  4. Załączniki → mail_attachments (bez treści, treść IMAP na żądanie)
```

---

## 8. SPAM — mechanizm

```
Dodanie nadawcy do spamu:
  POST messages/{id}/spam
    → SpamSender::firstOrCreate({from_email})
    → Message::whereRaw('LOWER(from_email)=?')->update({is_spam:true})
       (wszystkie dotychczasowe maile tego nadawcy)

Przy synchronizacji (nowe maile):
  MailSyncService → $spam = SpamSender::pluck('from_email')->flip()
  → foreach mail: is_spam = $spam->has(LOWER(from_email))

Filtr w index():
  filters.spam=true  → WHERE is_spam=1  (tylko spam, bez trash)
  filters.spam=false → WHERE is_spam=0  (wyklucz spam z każdego widoku)

Liczniki nie liczą spamu:
  accUnread, userUnread, catUnread, catalogTree → WHERE is_spam=0
```

---

## 9. Drag & drop — mechanizm

```
[Front]
  <li draggable="true" @dragstart="onDragStart(e, m)">
    → dragMessage.value = {id, from}
    → e.dataTransfer.setData('text/plain', m.id)

  <button @dragover.prevent="onCatalogDragOver(c.id)"
          @drop="onCatalogDrop(c.id)">
    → podświetlenie: dragOverCatalogId === c.id

  "Wszystkie" → onCatalogDrop(null)   ← sentinel cofnięcia

[Back] POST argo-mail/messages/{id}/file-sender
  {catalog_id: number|null}

  catalog_id != null:
    SenderRule::updateOrCreate({from_email}, {catalog_id})
    Message::whereRaw('LOWER(from_email)=?')->update({catalog_id})

  catalog_id = null:
    rule → jeśli ma assigned_admin_user_id: zeruj tylko catalog_id
          jeśli nie ma osoby: delete()
    Message::whereRaw('LOWER(from_email)=?')->update({catalog_id: null})
```

---

## 10. Filtr po kolorach — mechanizm

```
[Backend — liczniki]
  colorCounts = (clone $query)          ← ten sam zakres co lista (konto/katalog/itd.)
    ->reorder()
    ->whereNotNull('color_flag')
    ->selectRaw('color_flag, COUNT(*) as c')
    ->groupBy('color_flag')
    ->pluck('c', 'color_flag')
  → {red:5, green:3, blue:3, orange:3}

[Backend — filtr]
  filters.color != null:
    → $query->where('color_flag', filters.color)
    → NIE stosuje filtra "unread" (kolor > nieprzeczytane)

[Front]
  selectColor(color):
    → jeśli active: navigate({color: null})   ← toggle off
    → jeśli nie:   localFilters.unread = false + navigate({color})

  Po setColor():
    → router.reload({only:['colorCounts']})   ← odśwież kwadraciki
```

---

## 11. Szyfrowanie i bezpieczeństwo

```
Hasła/tokeny:
  Account.password, Account.oauth_token → cast 'encrypted' (APP_KEY AES-256)
  $hidden → nigdy nie trafiają do front-endu (JSON / Inertia props)

Treść maili (XSS):
  <iframe :srcdoc="bodyDoc"
    sandbox="allow-popups allow-popups-to-escape-sandbox">
  → skrypty w treści maila ZABLOKOWANE
  → linki otwierają się w nowej karcie (<base target="_blank">)
  → referrer zablokowany (no-referrer)

Załączniki:
  Treść pobierana z IMAP dopiero przy żądaniu pobrania (downloadAttachment)
  → nie składowana na dysku, serwowana wprost z IMAP response
```

---

## 12. Zależności zewnętrzne

| Paczka | Rola |
|---|---|
| `webklex/laravel-imap ^6.2` | Odbiór IMAP (pure PHP, bez ext-imap) |
| `symfony/mailer` | Wysyłka SMTP (EsmtpTransport) |
| `symfony/mime` | Budowanie wiadomości e-mail (Email, Address) |
| `openai-php/laravel` | Kategoryzacja AI (przez AiContentRuntimeConfig) |
| `@inertiajs/vue3` | SPA routing między Laravel a Vue |
| `@tiptap/*` (Wysiwyg) | Edytor HTML w kompozytorze |
| `axios` | Zapytania JSON z front-endu |
| `lodash.debounce` | Debouncowanie szukajki |
| `@brackets/vue-toastification` | Powiadomienia toast |
| `@heroicons/vue` | Ikony (outline + solid) |
