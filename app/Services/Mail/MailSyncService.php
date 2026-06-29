<?php

namespace App\Services\Mail;

use App\Models\AdminUser;
use App\Models\Mail\Account;
use App\Models\Mail\Folder;
use App\Models\Mail\MailUser;
use App\Models\Mail\Message;
use App\Models\Mail\SenderRule;
use App\Models\Mail\SpamSender;
use App\Models\Mail\ThreadExclude;
use App\Notifications\NewMailNotification;
use Webklex\IMAP\Facades\Client;

class MailSyncService
{
    /** Cache ID-ków obsługujących pocztę (mail_users) — adresaci push o nowym mailu bez przypisania. */
    private ?array $mailHandlerIds = null;

    /**
     * Synchronizuje wszystkie zwykłe foldery skrzynki (INBOX + foldery własne, np. „GlobKurier")
     * przyrostowo po UID, w oknie ostatnich N miesięcy. Foldery specjalne (Wysłane/Szkice/Kosz/Spam
     * oraz wirtualne [Gmail]/*) pomijamy — patrz syncableFolders(). Maile ze WSZYSTKICH folderów
     * trafiają do jednej wspólnej listy panelu (folder_id = tylko „skąd dociągnąć treść").
     *
     * @return array{ok: bool, fetched: int, new: int, updated: int, message?: string}
     */
    public function sync(Account $account, int $cap = 200): array
    {
        @ini_set('memory_limit', '512M'); // webklex parsuje całe MIME w pamięci — 128M CLI bywa za mało na duże maile

        $account->forceFill(['sync_status' => Account::SYNC_SYNCING])->save();

        $previousTimeout = ini_get('default_socket_timeout');
        ini_set('default_socket_timeout', '30');

        try {
            $client = Client::make($account->imapConfig());
            $client->connect();

            $imapFolders = $this->syncableFolders($client);
            if (empty($imapFolders)) {
                throw new \RuntimeException('Nie znaleziono żadnego folderu do synchronizacji.');
            }

            // Reguły „nadawca → osoba/katalog" (stosowane do nowych maili).
            $rules = SenderRule::all();

            // Lista nadawców spamu — ich maile od razu trafiają do spamu (ukryte z głównej skrzynki).
            $spam = SpamSender::query()->get(['from_email', 'subject_contains'])
                ->map(fn ($s) => ['email' => mb_strtolower(trim((string) $s->from_email)), 'subject' => mb_strtolower(trim((string) $s->subject_contains))])
                ->all();

            // Wykluczenia z grupowania (nadawca + opcjonalny fragment tytułu) — pasujące maile dostają unikatowy thread_key.
            $noGroup = ThreadExclude::query()->get(['from_email', 'subject_contains'])
                ->map(fn ($r) => ['email' => mb_strtolower(trim((string) $r->from_email)), 'subject' => mb_strtolower(trim((string) $r->subject_contains))])
                ->all();

            $fetched = 0;
            $new = 0;
            $updated = 0;
            $newByRecipient = [];

            foreach ($imapFolders as $imapFolder) {
                $folder = Folder::firstOrCreate(
                    ['account_id' => $account->id, 'path' => $imapFolder->path],
                    ['name' => $imapFolder->name]
                );

                $query = $imapFolder->messages()
                    ->setFetchBody(true)
                    ->setFetchOrder('desc')
                    ->limit($cap);

                if ($folder->last_uid > 0) {
                    // przyrostowo — ostatnie kilka dni (łapie nowe maile; istniejące pomija updateOrCreate)
                    $query->since(now()->subDays(3));
                } else {
                    // pierwsza synchronizacja folderu — okno ostatnich N miesięcy
                    $query->since(now()->subMonths(max(1, (int) $account->sync_window_months)));
                }

                try {
                    $messages = $query->get();
                } catch (\Throwable) {
                    // pojedynczy folder może odmówić (brak uprawnień / zniknął) — pomiń, leć dalej
                    continue;
                }

                $isFirstSync = ((int) $folder->last_uid) === 0;
                $maxUid = (int) $folder->last_uid;

                foreach ($messages as $message) {
                    try {
                        $row = $this->persistMessage($account, $folder, $message, $rules, $spam, $noGroup);
                        if (! $row) {
                            continue;
                        }
                        $maxUid = max($maxUid, (int) $message->uid);
                        $row->wasRecentlyCreated ? $new++ : $updated++;

                        // Zbierz nowe maile do push (poza pierwszą synchronizacją folderu, bez spamu, świeże).
                        if ($row->wasRecentlyCreated && ! $isFirstSync && ! $row->is_spam
                            && ($row->date === null || $row->date->gte(now()->subDay()))) {
                            $recipientIds = $row->assigned_admin_user_id
                                ? [(int) $row->assigned_admin_user_id]
                                : $this->mailHandlerIds();
                            foreach ($recipientIds as $rid) {
                                if (! isset($newByRecipient[$rid])) {
                                    $newByRecipient[$rid] = ['count' => 0, 'latest' => $row];
                                }
                                $newByRecipient[$rid]['count']++;
                            }
                        }
                    } catch (\Throwable) {
                        // pomiń pojedynczą wadliwą wiadomość, kontynuuj resztę
                        continue;
                    }
                }

                $folder->forceFill([
                    'last_uid'       => $maxUid,
                    'messages_count' => Message::where('folder_id', $folder->id)->count(),
                    'unread_count'   => Message::where('folder_id', $folder->id)->where('is_read', false)->count(),
                    'last_synced_at' => now(),
                ])->save();

                $fetched += $messages->count();
            }

            $client->disconnect();

            $account->forceFill([
                'sync_status'  => Account::SYNC_IDLE,
                'sync_error'   => null,
                'last_sync_at' => now(),
            ])->save();

            // Push o nowym mailu — jedno powiadomienie na adresata na ten przebieg.
            $this->dispatchNewMailNotifications($newByRecipient);

            return ['ok' => true, 'fetched' => $fetched, 'new' => $new, 'updated' => $updated];
        } catch (\Throwable $e) {
            $account->forceFill([
                'sync_status' => Account::SYNC_ERROR,
                'sync_error'  => mb_substr($e->getMessage(), 0, 500),
            ])->save();

            return ['ok' => false, 'fetched' => 0, 'new' => 0, 'updated' => 0, 'message' => $e->getMessage()];
        } finally {
            ini_set('default_socket_timeout', (string) $previousTimeout);
        }
    }

    /**
     * Pełny import wsadowy (backfill historii) — wszystkie zwykłe foldery skrzynki albo jeden
     * wskazany ($folderPath, np. „GlobKurier"). Leci paczkami przez webklex `chunked()`
     * (jeden SEARCH po UID-ach, potem ściąganie treści partiami), bez limitu czasu — do
     * uruchamiania z CLI (`php artisan mail:import`), NIE z żądania HTTP.
     * $months = 0 → cała historia; w przeciwnym razie okno ostatnich N miesięcy.
     *
     * @param  ?string  $folderPath  konkretny folder IMAP (puste = wszystkie zwykłe foldery)
     * @param  (callable(int $total, int $new, int $updated, int $page): void)|null  $progress
     * @return array{ok: bool, fetched: int, new: int, updated: int, message?: string}
     */
    public function import(Account $account, int $months = 0, int $batch = 100, ?string $folderPath = null, ?callable $progress = null): array
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $previousTimeout = ini_get('default_socket_timeout');
        ini_set('default_socket_timeout', '60');

        $account->forceFill(['sync_status' => Account::SYNC_SYNCING])->save();

        $new = 0;
        $updated = 0;
        $total = 0;

        try {
            $client = Client::make($account->imapConfig());
            $client->connect();

            if ($folderPath !== null && $folderPath !== '') {
                // jeden wskazany folder — honorujemy nawet „specjalny", skoro user podał go wprost
                $one = $client->getFolderByPath($folderPath);
                if (! $one) {
                    throw new \RuntimeException("Nie znaleziono folderu: {$folderPath}");
                }
                $imapFolders = [$one];
            } else {
                $imapFolders = $this->syncableFolders($client);
            }

            if (empty($imapFolders)) {
                throw new \RuntimeException('Nie znaleziono żadnego folderu do importu.');
            }

            $rules = SenderRule::all();
            $spam = SpamSender::query()->get(['from_email', 'subject_contains'])
                ->map(fn ($s) => ['email' => mb_strtolower(trim((string) $s->from_email)), 'subject' => mb_strtolower(trim((string) $s->subject_contains))])
                ->all();

            // Wykluczenia z grupowania (nadawca + opcjonalny fragment tytułu) — pasujące maile dostają unikatowy thread_key.
            $noGroup = ThreadExclude::query()->get(['from_email', 'subject_contains'])
                ->map(fn ($r) => ['email' => mb_strtolower(trim((string) $r->from_email)), 'subject' => mb_strtolower(trim((string) $r->subject_contains))])
                ->all();

            foreach ($imapFolders as $imapFolder) {
                $folder = Folder::firstOrCreate(
                    ['account_id' => $account->id, 'path' => $imapFolder->path],
                    ['name' => $imapFolder->name]
                );
                $maxUid = (int) $folder->last_uid;

                $query = $imapFolder->messages()
                    ->setFetchBody(true)
                    ->setFetchOrder('desc');

                if ($months > 0) {
                    $query->since(now()->subMonths($months));
                } else {
                    $query->whereAll();
                }

                $query->chunked(function ($messages, int $page) use ($account, $folder, $rules, $spam, &$new, &$updated, &$total, &$maxUid, $progress) {
                    foreach ($messages as $message) {
                        try {
                            $row = $this->persistMessage($account, $folder, $message, $rules, $spam, $noGroup);
                            if (! $row) {
                                continue;
                            }
                            $maxUid = max($maxUid, (int) $message->uid);
                            $row->wasRecentlyCreated ? $new++ : $updated++;
                        } catch (\Throwable) {
                            // pomiń pojedynczą wadliwą wiadomość, kontynuuj resztę
                            continue;
                        }
                    }

                    $total += $messages->count();

                    if ($progress) {
                        $progress($total, $new, $updated, $page);
                    }
                }, max(1, $batch));

                // last_uid zapisujemy DOPIERO po pełnym przejściu folderu (przerwany import nie zostawi luki).
                $folder->forceFill([
                    'last_uid'       => $maxUid,
                    'messages_count' => Message::where('folder_id', $folder->id)->count(),
                    'unread_count'   => Message::where('folder_id', $folder->id)->where('is_read', false)->count(),
                    'last_synced_at' => now(),
                ])->save();
            }

            $client->disconnect();

            $account->forceFill([
                'sync_status'  => Account::SYNC_IDLE,
                'sync_error'   => null,
                'last_sync_at' => now(),
            ])->save();

            return ['ok' => true, 'fetched' => $total, 'new' => $new, 'updated' => $updated];
        } catch (\Throwable $e) {
            $account->forceFill([
                'sync_status' => Account::SYNC_ERROR,
                'sync_error'  => mb_substr($e->getMessage(), 0, 500),
            ])->save();

            return ['ok' => false, 'fetched' => $total, 'new' => $new, 'updated' => $updated, 'message' => $e->getMessage()];
        } finally {
            ini_set('default_socket_timeout', (string) $previousTimeout);
        }
    }

    /**
     * Foldery serwera, z których wciągamy maile do wspólnej skrzynki: INBOX + wszystkie zwykłe
     * foldery użytkownika (np. „GlobKurier"). Pomijamy foldery specjalne/wirtualne — patrz
     * isSpecialFolder(). Maile ze wszystkich tych folderów lądują w jednej liście panelu
     * (lista nie filtruje po folderze serwera); folder_id to tylko „skąd dociągnąć treść".
     *
     * @param  \Webklex\PHPIMAP\Client  $client
     * @return array<int, \Webklex\PHPIMAP\Folder>
     */
    private function syncableFolders($client): array
    {
        try {
            $folders = $client->getFolders(false); // płaska lista wszystkich folderów serwera
        } catch (\Throwable) {
            // gdy listowanie folderów zawiedzie — awaryjnie sam INBOX
            $inbox = $client->getFolderByPath('INBOX');

            return $inbox ? [$inbox] : [];
        }

        $out = [];
        foreach ($folders as $folder) {
            if (! empty($folder->no_select)) {
                continue; // kontener bez maili (np. „[Gmail]")
            }
            if ($this->isSpecialFolder((string) $folder->path, (string) $folder->name)) {
                continue;
            }
            $out[] = $folder;
        }

        return $out;
    }

    /**
     * Czy folder jest „specjalny" (systemowy/wirtualny) i ma być pominięty przy synchronizacji.
     * Rozpoznajemy po przestrzeni Gmaila ([Gmail]/[Google Mail] — w tym wirtualne „Wszystkie",
     * które dublują całą skrzynkę) oraz po typowych nazwach folderów Wysłane/Szkice/Kosz/Spam
     * (PL i EN; obejmuje też warianty „INBOX.Sent" dzięki użyciu samej nazwy-liścia).
     * INBOX, Archiwum i foldery własne (GlobKurier itp.) NIE są specjalne.
     */
    private function isSpecialFolder(string $path, string $name): bool
    {
        $name = mb_strtolower(trim($name));
        if ($name === '' || $name === 'inbox') {
            return false;
        }

        // Cała przestrzeń Gmaila (Wysłane/Kosz/Spam/Szkice/Ważne/Oznaczone/„Wszystkie" w każdym języku).
        $p = mb_strtolower($path);
        if (str_starts_with($p, '[gmail]') || str_starts_with($p, '[google mail]')) {
            return true;
        }

        // Typowe foldery systemowe zwykłych serwerów (po nazwie-liściu).
        $special = [
            // Wysłane
            'sent', 'sent items', 'sent mail', 'sent messages', 'wysłane', 'wyslane', 'elementy wysłane',
            // Szkice / robocze
            'drafts', 'draft', 'szkice', 'wersje robocze', 'kopie robocze', 'robocze',
            // Kosz
            'trash', 'deleted', 'deleted items', 'deleted messages', 'bin', 'kosz', 'usunięte', 'usuniete', 'elementy usunięte',
            // Spam / niechciane
            'junk', 'junk e-mail', 'junk email', 'spam', 'bulk mail', 'niechciane', 'niechciana poczta',
            // Inne nie-przychodzące
            'outbox', 'wychodzące', 'wychodzace', 'templates', 'szablony',
        ];

        return in_array($name, $special, true);
    }

    /**
     * Zapisuje/aktualizuje pojedynczą wiadomość IMAP (upsert po UID + auto-filing wg reguł
     * + załączniki). Wspólne dla sync() (przyrostowo) i import() (wsadowo).
     * Zwraca model wiadomości (z flagą wasRecentlyCreated) albo null gdy pominięto.
     *
     * @param  \Illuminate\Support\Collection<string, SenderRule>  $rules
     * @param  \Illuminate\Support\Collection<string, int>  $spam
     */
    private function persistMessage(Account $account, Folder $folder, mixed $message, $rules, $spam, array $noGroup = []): ?Message
    {
        $uid = (int) $message->uid;
        if ($uid <= 0) {
            return null;
        }

        $from = $this->addresses($message->from);
        $first = $from[0] ?? ['email' => '', 'name' => ''];

        $html = $message->getHTMLBody();
        $text = $message->getTextBody();
        $flags = $message->getFlags()->toArray();

        $date = null;
        try {
            // Nagłówek Date ma offset strefy (np. +0200) — webklex parsuje go ze strefą.
            // app.timezone=UTC → przy ZAPISIE Eloquent gubi offset (zapisałby cyfry lokalne jako UTC,
            // stąd maile „+2h"). Normalizujemy do UTC ZANIM zapiszemy; front sam przeliczy na strefę przeglądarki.
            $parsed = $message->date?->toDate();
            if ($parsed) {
                $date = \Illuminate\Support\Carbon::instance($parsed)->utc();
            }
        } catch (\Throwable) {
            // brak / niewłaściwy nagłówek Date
        }

        $routing = $this->resolveRouting($first['email'] ?? '', (string) ($message->subject ?? ''), $rules, $spam);

        // Wątkowanie: „druga strona" = nadawca; dla maili z naszego własnego adresu (np. zsynchronizowany
        // folder Wysłane) bierzemy odbiorcę, żeby zgrupować je z resztą rozmowy.
        $fromAddr = mb_strtolower(trim((string) ($first['email'] ?? '')));
        $toAddr = mb_strtolower(trim((string) ($this->addresses($message->to)[0]['email'] ?? '')));
        $counterpart = ($fromAddr !== '' && $fromAddr === mb_strtolower(trim((string) $account->email))) ? $toAddr : $fromAddr;

        // Zwykle wspólny klucz (temat+rozmówca). Ale maile z reguł „bez grupowania" (np. zamówienia
        // Allegro/Amazon) dostają UNIKATOWY klucz, żeby każdy mail stał osobno (nie zwijał się w wątek).
        $threadKey = Message::threadKeyFor($message->subject, $counterpart);
        if ($noGroup && $this->matchesRule($first['email'] ?? '', (string) ($message->subject ?? ''), $noGroup)) {
            $ngBase = $this->str($message->message_id, 512) ?: ($account->id.'|'.$folder->id.'|'.$uid);
            $threadKey = 'ng:'.sha1((string) $ngBase);
        }

        $row = Message::updateOrCreate(
            [
                'account_id' => $account->id,
                'folder_id'  => $folder->id,
                'uid'        => $uid,
            ],
            [
                'message_id'      => $this->str($message->message_id, 512),
                'subject'         => $this->str($message->subject),
                'from_email'      => mb_substr($first['email'], 0, 255),
                'from_name'       => mb_substr($first['name'], 0, 255),
                'to_recipients'   => $this->addresses($message->to),
                'cc_recipients'   => $this->addresses($message->cc),
                'date'            => $date,
                'snippet'         => Message::makeSnippet($text, $html),
                'body_html'       => $html !== '' ? $html : null,
                'body_text'       => $text !== '' ? $text : null,
                'has_attachments' => $message->hasAttachments(),
                'size'            => (int) ($message->size ?? 0) ?: null,
                'is_flagged'      => $this->hasFlag($flags, 'Flagged'),
                'in_reply_to'     => $this->str($message->in_reply_to, 512),
                'thread_key'      => $threadKey,
                'is_spam'         => $routing['is_spam'],
            ]
        );

        // is_read ustawiamy TYLKO przy TWORZENIU maila (z flagi serwera \Seen). Przy istniejących
        // sync NIE rusza is_read — lokalne „przeczytane" jest źródłem prawdy (bez tego sync co minutę
        // cofałby odczyty, bo flagi nie wypychamy na serwer IMAP). + auto-filing wg reguł (resolveRouting).
        if ($row->wasRecentlyCreated) {
            $patch = ['is_read' => $this->hasFlag($flags, 'Seen')];
            if ($routing['assigned_admin_user_id']) {
                $patch['assigned_admin_user_id'] = $routing['assigned_admin_user_id'];
            }
            if ($routing['catalog_id']) {
                $patch['catalog_id'] = $routing['catalog_id'];
            }
            if ($patch) {
                $row->forceFill($patch)->save();
            }
        }

        if ($message->hasAttachments()) {
            $row->attachments()->delete();
            $idx = 0;
            foreach ($message->getAttachments() as $att) {
                $row->attachments()->create([
                    'part_index' => $idx,
                    'filename'   => mb_substr((string) ($att->name ?? ('zalacznik-'.($idx + 1))), 0, 255),
                    'mime'       => $att->getMimeType(),
                    'size'       => (int) ($att->size ?? 0) ?: null,
                ]);
                $idx++;
            }
        }

        return $row;
    }

    /**
     * Rozstrzyga, gdzie trafia mail: katalog / osoba / spam — wg reguł nadawcy (domena lub konkretny
     * adres, opcjonalnie + słowo w temacie) i listy spamu (adres lub @domena). Priorytety:
     *   1. WYKLUCZENIE (konkretny adres ALBO domena+słowo w temacie) → jego katalog, BEZ spamu.
     *   2. SPAM (adres lub domena) → is_spam = true.
     *   3. Ogólna reguła domenowa → jej katalog.
     * Specyficzność reguły: konkretny adres (2) > domena (0); + słowo w temacie (+1).
     *
     * @param  \Illuminate\Support\Collection<int, SenderRule>  $rules
     * @param  array<int, array{email: string, subject: string}>  $spamList  reguły spamu (adres/@domena + opcjonalny fragment tytułu)
     * @return array{catalog_id: ?int, assigned_admin_user_id: ?int, is_spam: bool}
     */
    private function resolveRouting(string $email, string $subject, $rules, $spamList): array
    {
        $email = mb_strtolower(trim($email));
        $domain = $this->emailDomain($email);
        $subjectLower = mb_strtolower($subject);

        $best = null;
        $bestScore = -1;
        $bestIsException = false;

        foreach ($rules as $rule) {
            $rf = mb_strtolower(trim((string) $rule->from_email));
            if ($rf === '') {
                continue;
            }
            $isDomainRule = str_starts_with($rf, '@');
            $matchesSender = $isDomainRule
                ? ($domain !== '' && $rf === '@'.$domain)
                : ($rf === $email);
            if (! $matchesSender) {
                continue;
            }

            $keyword = mb_strtolower(trim((string) $rule->subject_contains));
            if ($keyword !== '' && ! str_contains($subjectLower, $keyword)) {
                continue;
            }

            $score = ($isDomainRule ? 0 : 2) + ($keyword !== '' ? 1 : 0);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $rule;
                // Wykluczenie = reguła na konkretny adres LUB z warunkiem słowa w temacie.
                $bestIsException = ! $isDomainRule || $keyword !== '';
            }
        }

        // Spam: dopasowanie po nadawcy/domenie + opcjonalnym fragmencie tytułu (puste = cały nadawca).
        $isSpam = false;
        foreach ($spamList as $sp) {
            $se = mb_strtolower(trim((string) ($sp['email'] ?? '')));
            if ($se === '') {
                continue;
            }
            $matchSender = str_starts_with($se, '@')
                ? ($domain !== '' && $se === '@'.$domain)
                : ($se === $email);
            if (! $matchSender) {
                continue;
            }
            $kw = (string) ($sp['subject'] ?? '');
            if ($kw !== '' && ! str_contains($subjectLower, $kw)) {
                continue;
            }
            $isSpam = true;
            break;
        }

        // 1. Wykluczenie bije spam i regułę ogólną.
        if ($best && $bestIsException) {
            return [
                'catalog_id'             => $best->catalog_id,
                'assigned_admin_user_id' => $best->assigned_admin_user_id,
                'is_spam'                => false,
            ];
        }

        // 2. Spam.
        if ($isSpam) {
            return ['catalog_id' => null, 'assigned_admin_user_id' => null, 'is_spam' => true];
        }

        // 3. Ogólna reguła domenowa.
        if ($best) {
            return [
                'catalog_id'             => $best->catalog_id,
                'assigned_admin_user_id' => $best->assigned_admin_user_id,
                'is_spam'                => false,
            ];
        }

        return ['catalog_id' => null, 'assigned_admin_user_id' => null, 'is_spam' => false];
    }

    /**
     * Czy mail pasuje do którejś reguły (nadawca/@domena + opcjonalny fragment tytułu).
     * Wspólne dla wykluczeń z grupowania; ten sam wzorzec co lista spamu.
     *
     * @param  array<int, array{email: string, subject: string}>  $rules
     */
    private function matchesRule(string $email, string $subject, array $rules): bool
    {
        $email = mb_strtolower(trim($email));
        $domain = $this->emailDomain($email);
        $subjectLower = mb_strtolower($subject);

        foreach ($rules as $r) {
            $se = mb_strtolower(trim((string) ($r['email'] ?? '')));
            if ($se === '') {
                continue;
            }
            $match = str_starts_with($se, '@') ? ($domain !== '' && $se === '@'.$domain) : ($se === $email);
            if (! $match) {
                continue;
            }
            $kw = mb_strtolower(trim((string) ($r['subject'] ?? '')));
            if ($kw !== '' && ! str_contains($subjectLower, $kw)) {
                continue;
            }

            return true;
        }

        return false;
    }

    /** Część domenowa adresu (po ostatnim @), lowercase; '' gdy brak. */
    private function emailDomain(string $email): string
    {
        $at = mb_strrpos($email, '@');

        return $at === false ? '' : mb_strtolower(trim(mb_substr($email, $at + 1)));
    }

    /**
     * Zamienia atrybut adresowy webklex (kolekcja Address) na tablicę [['email','name'], ...].
     *
     * @return array<int, array{email: string, name: string}>
     */
    private function addresses(mixed $attr): array
    {
        if (! $attr) {
            return [];
        }

        $out = [];
        try {
            foreach ($attr->toArray() as $a) {
                if (is_object($a)) {
                    $mail = (string) ($a->mail ?? $a->email ?? '');
                    $name = (string) ($a->personal ?? '');
                } elseif (is_array($a)) {
                    $mail = (string) ($a['mail'] ?? $a['email'] ?? '');
                    $name = (string) ($a['personal'] ?? $a['name'] ?? '');
                } else {
                    $mail = (string) $a;
                    $name = '';
                }

                if ($mail !== '' || $name !== '') {
                    $out[] = ['email' => $mail, 'name' => $name];
                }
            }
        } catch (\Throwable) {
            // ignoruj problemy z parsowaniem adresów
        }

        return $out;
    }

    /**
     * @param  array<int, string>  $flags
     */
    private function hasFlag(array $flags, string $flag): bool
    {
        return in_array($flag, $flags, true) || in_array('\\'.$flag, $flags, true);
    }

    private function str(mixed $value, ?int $max = null): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return $max ? mb_substr($value, 0, $max) : $value;
    }

    private function snippet(?string $text): ?string
    {
        if (! $text) {
            return null;
        }

        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);

        return $text === '' ? null : mb_substr($text, 0, 200);
    }

    /** ID-ki użytkowników obsługujących pocztę (adresaci push dla maili bez przypisania). */
    private function mailHandlerIds(): array
    {
        if ($this->mailHandlerIds === null) {
            $this->mailHandlerIds = MailUser::query()
                ->whereNotNull('admin_user_id')
                ->pluck('admin_user_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();
        }

        return $this->mailHandlerIds;
    }

    /**
     * Wysyła po jednym powiadomieniu (database + webpush) na adresata, podsumowując nowe maile.
     *
     * @param  array<int, array{count: int, latest: \App\Models\Mail\Message}>  $newByRecipient
     */
    private function dispatchNewMailNotifications(array $newByRecipient): void
    {
        foreach ($newByRecipient as $rid => $info) {
            $user = AdminUser::find($rid);
            if (! $user) {
                continue;
            }

            try {
                $user->notify(new NewMailNotification(
                    count: (int) $info['count'],
                    fromName: $info['latest']->from_name ?: $info['latest']->from_email,
                    subject: $info['latest']->subject,
                ));
            } catch (\Throwable) {
                // push best-effort — nie przerywaj synchronizacji
            }
        }
    }
}
