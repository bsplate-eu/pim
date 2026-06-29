# Argo Mail — moduł poczty w PIM

> Status: **MVP gotowy + AI #1 + SPAM (adres/domena/tytuł) + załączniki + remindery + wątki + multi-folder sync + wykluczenia grupowania** · Start: 2026-05-29 · Aktualizacja: 2026-06-03 · Właściciel: PIM Local
>
> Changelog dzienny: `docs/argo-pim.md` (najnowsze na górze). Ten plik = aktualny stan/spec modułu.
>
> 🖥️ Dostępne też jako **program Windows** (lustro produkcji: zasobnik + autostart + dymki) — spec: [`argo-mail-desktop.md`](argo-mail-desktop.md).

Wspólny menedżer poczty wpięty w PIM — agreguje wiele firmowych skrzynek (Gmail i inne) w jednym panelu,
z katalogami, kategoriami AI, przypisywaniem do osób, wysyłką, stopkami i kolorowaniem (jak Thunderbird).

---

## 1. Cel

W firmie ~20 skrzynek (Gmail, Thunderbird). Problem: nie wiadomo gdzie co jest i kto ma obsługiwać dany mail.
Zamiast kupować Google Workspace — własny moduł w PIM (kolejny element „obiegu zamkniętego").

## 2. Koncept: AGREGATOR, nie serwer pocztowy

- **Nie stawiamy serwera pocztowego** (żadnych MX/SPF/DKIM/blacklist). Poczta zostaje na Gmailu/serwerach dostawców.
- **PIM = klient-agregator**: łączy się do skrzynek przez **IMAP (odbiór)** + **SMTP (wysyłka)** i dokłada warstwę
  „kto / do kogo / firma / kategoria / katalog / kolor".
- ⚠️ Sam SMTP nie wystarcza do agregacji — potrzebny IMAP. Każdą skrzynkę wpinamy IMAP + SMTP.

## 3. Decyzje

| Temat | Decyzja |
|---|---|
| Logowanie | **App Password** (Gmail, wymaga 2FA); OAuth2 — faza 3. Spacje w haśle aplikacji usuwane automatycznie. |
| Okno pobierania | 1. sync: ostatnie **N miesięcy** (domyślnie 6, ustawiane per skrzynka); przyrost: ostatnie **3 dni**. |
| Stos | Laravel 10 + Inertia/Vue 3 + Tailwind, `webklex/laravel-imap` (bez ext-imap), Symfony Mailer (SMTP), TipTap (`Wysiwyg`). |
| ⚠️ Klucz OpenAI | Stary `OPENAI_ORGANIZATION` w `.env` powodował 401 dla wszystkich wywołań OpenAI — wyczyszczony. |

## 4. Funkcje (stan na 2026-06-01)

### Skrzynki (konta) — `Argo Mail → Skrzynki`
- Dodawanie z **presetami Gmaila** (imap.gmail.com:993/ssl, smtp.gmail.com:465/ssl) lub ręcznie.
- **„Testuj połączenie"** — osobno IMAP i SMTP, bez zapisu.
- Edycja, usuwanie, **„Synchronizuj teraz"** (inline). Statystyki (liczba maili, nieprzeczytane), status syncu.
- **Stopka (podpis) per skrzynka** — auto-dodawana przy pisaniu/odpowiadaniu.

### Synchronizacja
- Pobiera **wszystkie zwykłe foldery** skrzynki (INBOX + własne, np. „GlobKurier" + Archiwum) przyrostowo (po dacie; `updateOrCreate` po `uid` pomija/aktualizuje istniejące). Foldery specjalne **pomijane**: Wysłane/Szkice/Kosz/Spam + cała przestrzeń Gmaila `[Gmail]/*` (w tym „Wszystkie" — dublowałaby skrzynkę). Wybór: `syncableFolders()`/`isSpecialFolder()` (denylist PL/EN + prefiks `[Gmail]`/`[Google Mail]` + flaga `no_select`).
  - **Maile ze wszystkich folderów trafiają do jednej wspólnej listy** — panel NIE pokazuje folderów serwera (`mail_folders`), `folder_id` to tylko „skąd dociągnąć treść/załącznik". Użytkownik rozdziela maile sam (katalogi/kategorie/kolory/reguły). Backfill historii folderu: `php artisan mail:import --folder="GlobKurier"`.
- Treść (HTML+text) i metadane załączników w bazie; **treść załącznika dociągana z IMAP na żądanie** (oszczędza dysk).
- **Automat co minutę**: komenda `mail:sync` (inline) + scheduler `everyMinute()`.
  - Lokalnie: `mail-sync-loop.bat` (pętla co 60 s) — brak crona Laravela.
  - Prod: cron `* * * * * php artisan schedule:run`.
- **Reguły nadawcy** stosowane przy syncu nowych maili (auto-przypisanie osoby/katalogu).

### Panel (`Argo Mail → Skrzynka`) — 3 kolumny: Katalogi | Lista | Podgląd
- Układ elastyczny (flex): katalogi wąskie, lista szersza, podgląd elastyczny. Wysokość `calc(100vh − …)`.
- **Filtry — 3 wiersze tabów**: Konta · Osoby · Kategorie (pigułki z kolorem + licznikiem nieprzeczytanych) + szukajka + „tylko nieprzeczytane".
  - **Filtr po kolorach**: obok „tylko nieprzeczytane" kwadraciki kolorów (tylko dla kolorów w użyciu — `colorCounts`); klik → tylko maile w tym kolorze, ponowny klik/„✕" zdejmuje.
- **Lista**: nadawca/temat czarne; **nieprzeczytane pogrubione/większe**; zaznaczone niebieskie.
  - **Multi-select**: Ctrl/Cmd+klik (zaznacz/odznacz), Shift+klik (zakres).
  - **Kolory (jak tagi Thunderbirda)** — tintują **cały wiersz**: `1`=czerwony, `2`=zielony, `3`=niebieski, `4`=pomarańczowy, `0`=bez koloru (toggle). Bez Ctrl (przeglądarka łapie Ctrl+cyfra).
  - Chipy: osoba / kategoria / katalog.
- **Prawy-klik (menu kontekstowe)**:
  - **Działania**: Oznacz jako nieodczytany / przeczytany.
  - Przypisz osobę (opcja **„na stałe"** → reguła nadawcy), Kategoria, Katalog (drzewo z wcięciami), **Oznacz jako SPAM (popup: adres/domena/fragment tytułu)** / Do kosza.
- **Pasek akcji masowych** (po zaznaczeniu): Do kosza, Przeczytane/Nieprzeczytane, Katalog, Kategoria, Osoba, kolory, Wyczyść.
- **Podgląd**: treść HTML w **sandboxed iframe** (linki otwierają się w nowej karcie; **skrypty zablokowane**); załączniki do pobrania;
  **Odpowiedz / Przekaż** (duże przyciski góra i dół), **Full size** (pełny ekran, chowa filtry/listę/katalogi), **SPAM**/„nie spam", kosz/przywróć, select katalogu.
- **Auto-odświeżanie co 60 s** (partial reload) — pokazuje maile dociągnięte w tle, odświeża liczniki i przypomnienie.
- **Przypomnienie (prawy dolny róg)**: pływająca karta „Nieprzeczytane wiadomości: N" + „Pokaż nieprzeczytane" + ukryj (wraca, gdy przybędą nowe).

### Wysyłka / kompozytor — Nowa / Odpowiedz / Przekaż
- Od (wybór konta), Do/DW (parsowane), Temat, **edytor HTML (TipTap) domyślnie włączony** (checkbox „Edytor HTML" → zwykły tekst).
- **Full size** okna. Stopka konta auto-doklejana. Reply ustawia `In-Reply-To`/`References`.
- **Załączniki**: „Dodaj załącznik" (wiele plików, lista z usuwaniem); wysyłka `FormData` → `attachFromPath` (do 20 plików / 15 MB każdy).
- Wysyłka przez **SMTP konta** (Symfony Mailer). Kopia wysłanego → katalog **SEND → [nazwa skrzynki]** (auto-tworzony).

### Katalogi — drzewo (Ustawienia → Katalogi)
- Drzewo `parent_id` (dodaj / podkatalog / zmień nazwę / usuń). **Szare ikony folderów** (solid), bold + licznik dla nieprzeczytanych.
- **Drag & drop**: przeciągnij mail z listy na katalog → mail tam trafia, **nadawca dostaje regułę „na stałe"** (`SenderRule`) i **wszystkie jego dotychczasowe maile** przenoszą się do tego katalogu. Upuszczenie na **„Wszystkie"** = cofnięcie (zdejmuje katalog ze wszystkich maili nadawcy + kasuje regułę). (`argo-mail.messages.file-sender`, `catalog_id` nullable)
- **Kosz** przypięty na dole (stan `is_trashed`); klawisz **Del** w otwartym mailu → do kosza, w koszu Del = przywróć. Maile w koszu ukryte w zwykłych widokach.
- **Spam** przypięty pod Koszem (stan `is_spam`) — patrz niżej.
- **SEND/[skrzynka]** — auto-katalog na wysłane.

### Kategorie — etykiety AI (Ustawienia → Kategorie)
- 8 domyślnych (Klienci, Zamówienia, Faktury, Reklamacje, Dostawcy, Marketing, Wewnętrzne, Inne), CRUD, kolory.
- **Auto-kategoryzacja AI**: `Narzędzia AI → Mail → Administrator → „Kategoryzuj AI"` (OpenAI, batch po 25 nieskategoryzowanych).

### Osoby (Ustawienia → Osoby)
- Wskazujesz **konta systemu PIM** (AdminUser) do obsługi poczty + kolor etykiety → pojawiają się jako taby „Osoby" i w przypisaniach.

### Spam (manualny, edytowalny) — Ustawienia → Spam
- **Oznacz jako SPAM** (prawy-klik / przycisk w podglądzie) → **popup** (jak „Nowy filtr z maila"): pole nadawcy + checkbox **„Cała domena @… do spamu"** + opcjonalne **„Tytuł zawiera"**. Wpis trafia na listę `mail_spam_senders`, a pasujące maile **znikają z głównej skrzynki** (widoczne tylko w folderze **„Spam"**). „Nie spam (przywróć nadawcę)" cofa (usuwa regułę + odflagowuje wg nadawcy i tytułu).
  - **Adres / domena / fragment tytułu**: blokujesz pojedynczy adres, **całą domenę** (`@domena.pl`), albo tylko **część maili nadawcy** wg słowa w temacie (np. Allegro `powiadomienia@allegro.pl` + „Dyskusja" → reszta zostaje w skrzynce). Unikat `(from_email, subject_contains)` → wiele reguł na jednego nadawcę.
- **Auto-spam przy synchronizacji**: nowe maile pasujące do reguły (nadawca/domena + opcjonalny fragment tytułu) są od razu oznaczane `is_spam` (`resolveRouting`).
- **Ustawienia → Spam**: lista reguł (adres/@domena + badge „temat zawiera: …" + licznik maili), ręczne dodanie (adres/domena + opcjonalny tytuł), „Przywróć (nie spam)".
- Spam jest **wykluczony z liczników** (Konta/Osoby/Kategorie/Katalogi) i z przypomnienia o nieprzeczytanych.
- AI (auto-wykrywanie spamu) — planowane; tu zbudowany jest fundament manualny.

### Wykluczenia z grupowania (wątkowania) — Ustawienia → „Bez grupowania"
- Reguła `nadawca/@domena + opcjonalny fragment tytułu`: pasujące maile **NIE są zwijane w wątek** — każdy stoi osobno na liście.
- **Po co:** zamówienia z **Allegro/Amazon** lecą z jednego adresu z podobnym tematem → bez tego zlepiały się w jedną rozmowę. Teraz każde zamówienie = osobny wiersz.
- **Mechanizm:** pasujący mail dostaje **unikatowy `thread_key`** (`ng:`+sha1 z message_id/uid) zamiast wspólnego (`MailSyncService::persistMessage` + helper `matchesRule`; reguły ładowane jak lista spamu). Dotyczy nowych (sync) i istniejących (przeliczane przy dodaniu/usunięciu reguły).
- Tabela `mail_thread_excludes`, model `ThreadExclude`, trasy `argo-mail.thread-excludes.{store,destroy}`. **Dodanie** rozgrupowuje istniejące pasujące maile, **usunięcie** przelicza `thread_key` z powrotem (grupuje znów).

## 5. Model danych

| Tabela | Kluczowe kolumny |
|---|---|
| `mail_accounts` | label, email, imap_*, smtp_*, username, `password`/`oauth_token` (encrypted, $hidden), sync_window_months, **signature**, sync_status, last_sync_at |
| `mail_folders` | account_id, name, path (INBOX / `__SENT_LOCAL`), last_uid, liczniki |
| `mail_messages` | account_id, folder_id, **category_id**, **catalog_id**, **assigned_admin_user_id**, uid, message_id, from/to/cc, subject, date, snippet, body_html/text, has_attachments, is_read, is_flagged, **is_trashed**/trashed_at, **is_sent**, **is_spam**, **color_flag**, in_reply_to |
| `mail_attachments` | message_id, part_index, filename, mime, size (treść z IMAP na żądanie) |
| `mail_categories` | name, color, is_system, sort |
| `mail_catalogs` | name, parent_id (drzewo), color, sort |
| `mail_users` | admin_user_id (unique), color, sort |
| `mail_sender_rules` | from_email (unique), assigned_admin_user_id, catalog_id |
| `mail_spam_senders` | from_email + **subject_contains** (unikat złożony; lowercase) — reguły spamu: adres/@domena + opcjonalny fragment tytułu |
| `mail_thread_excludes` | from_email + subject_contains (unikat złożony) — reguły „bez grupowania" (wątkowania): pasujące maile dostają unikatowy `thread_key` |

## 6. Routy / pliki

- Routy: `argo-mail.index`, `argo-mail.settings`, `argo-mail.accounts.*` (+ `test`, `sync`), `argo-mail.catalogs.*`,
  `argo-mail.mail-users.*`, `argo-mail.send`, `argo-mail.spam.{store,destroy}`, `argo-mail.thread-excludes.{store,destroy}`,
  `argo-mail.messages.{show,attachment,catalog,file-sender,user,category,trash,spam,unspam,bulk,color}`,
  `ai-tools.mail.{administrator,categorize,categories.store,categories.destroy}`.
- Backend: `App\Http\Controllers\Admin\Mail\{MailController,AccountController,CatalogController,MailUserController}`,
  `App\Http\Controllers\Admin\AiAgents\AiToolsMailController`, `App\Services\Mail\{MailSyncService,MailAiCategorizer}`,
  `App\Jobs\Mail\SyncMailAccountJob`, komendy `App\Console\Commands\{MailSync,MailImport,MailRebuildSnippets,MailBackfillThreadKeys}`, modele `App\Models\Mail\{Account,Folder,Message,Attachment,Category,Catalog,MailUser,SenderRule,SpamSender,ThreadExclude}`.
- Front: `resources/js/crafter/Pages/ArgoMail/{Index.vue,Settings.vue,Accounts/Index.vue,Accounts/Form.vue}`,
  `resources/js/crafter/Pages/AiAgents/Tools/Mail/Administrator.vue`. Menu: grupa „Argo Mail" + „Narzędzia AI → Mail → Administrator".

## 7. Bezpieczeństwo
- Hasła/tokeny szyfrowane (`APP_KEY`), `$hidden` (nie wychodzą do front-endu).
- Treść maili w **sandboxed iframe** bez `allow-scripts` (skrypty zablokowane). Sandbox: `allow-popups allow-popups-to-escape-sandbox allow-same-origin` — `allow-same-origin` służy WYŁĄCZNIE do pomiaru wysokości treści (auto-rozmiar iframe); bez `allow-scripts` jest bezpieczne (maile nie wykonują JS, więc nie mają jak wykorzystać same-origin). **Nigdy nie dodawać `allow-scripts`** (z `allow-same-origin` zdjęłoby sandbox). Brak serwera pocztowego = mniejsza powierzchnia ataku.

## 8. Status i co dalej
- ✅ Skrzynki, sync (auto co min. + auto-odświeżanie panelu), panel 3-kol, katalogi+Kosz+Spam+SEND, kategorie+AI auto-kategoryzacja,
  osoby+reguły nadawcy, wysyłka (Nowa/Odpowiedz/Przekaż)+stopki+kompozytor HTML+**załączniki**, multi-select+akcje masowe, kolory,
  prawy-klik (Działania), klikalne linki, **SPAM (manualny, edytowalny)**, **przypomnienie (prawy dolny róg)**.
- ⏭️ Planowane: **profile firm** (A), **AI #2 wykrywanie spamu** (na fundamencie manualnym), dwukierunkowa synchronizacja flag (Seen) z IMAP,
  **OAuth2** zamiast App Password, pobieranie załączników z maili wysłanych.
- ✅ **Multi-folder sync (2026-06-03):** sync/import czytają wszystkie zwykłe foldery serwera (nie tylko INBOX); Wysłane/Kosz/Spam/Szkice + `[Gmail]/*` świadomie pomijane. Opcjonalnie do dorobienia: serwerowe „Wysłane"/„Spam" jako osobne źródła, filtr po folderze serwera w panelu, limit folderów per skrzynka (wydajność).
- ✅ **Batch UI/spam (2026-06-03):** drzewo katalogów w dropdownach (wcięcia), **SPAM popup** (adres / cała domena / fragment tytułu), katalog wysłanych **„SEND"→„Wysłane"**, fix snippetów (wyciek CSS z `<style>`), **podgląd maila na pełną wysokość** (iframe auto-rozmiar, `allow-same-origin`), **wykluczenia z grupowania** (zamówienia Allegro/Amazon osobno). Deploy: jedna paczka `argo-mail-pelny.zip` (zastępuje multifolder/ui/wyslane).
- 💡 **Pomysł: „SPAM hardcore" (omówiony 2026-06-03, NIE zbudowany).** Druga, ostrzejsza opcja spamu: oznaczasz → **cała domena** do spamu → **kasuje** istniejące maile (z bazy PIM) → **blokuje wciąganie** nowych z tej domeny (pomijane w `persistMessage()`, we wszystkich folderach). Silnik domenowy już jest (`resolveRouting()` matchuje `@domena`); brakuje: kolumny `hard_block` w `mail_spam_senders`, akcji „SPAM hardcore (cała domena)" z potwierdzeniem (kasuje N), i skipu zapisu. **⚠️ Caveat:** jesteśmy agregatorem — „kasuje" = z PIM, **nie** z serwera poczty (odwracalne przez re-import). Szczegóły: `docs/daily/03-06-2026.md`.
