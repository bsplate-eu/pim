<?php

namespace App\Http\Controllers\Admin\Mail;

use App\Http\Controllers\Admin\Controller;
use App\Models\AdminUser;
use App\Models\Mail\Account;
use App\Models\Mail\Attachment;
use App\Models\Mail\Catalog;
use App\Models\Mail\Category;
use App\Models\Mail\Folder;
use App\Models\Mail\MailUser;
use App\Models\Mail\Message;
use App\Models\Mail\SenderRule;
use App\Models\Mail\SpamSender;
use App\Models\Mail\ThreadExclude;
use App\Services\Mail\MailSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Webklex\IMAP\Facades\Client;

class MailController extends Controller
{
    /**
     * Wspólny panel poczty — katalogi + 3 wiersze tabów (konta/osoby/kategorie) + lista.
     */
    public function index(Request $request): Response
    {
        $accounts = Account::query()->orderBy('label')->get();
        $accountIds = $accounts->pluck('id');

        $filters = [
            'account_id'  => $request->integer('account_id') ?: null,
            'user_id'     => $request->integer('user_id') ?: null,
            'category_id' => $request->integer('category_id') ?: null,
            'catalog_id'  => $request->integer('catalog_id') ?: null,
            'unread'      => $request->boolean('unread'),
            'unfiled'     => $request->boolean('unfiled'),
            'trash'       => $request->boolean('trash'),
            'spam'        => $request->boolean('spam'),
            'color'       => in_array($request->get('color'), ['red', 'green', 'blue', 'orange'], true) ? $request->get('color') : null,
            'q'           => trim((string) $request->get('q')) ?: null,
            'sort'        => in_array($request->get('sort'), ['date_desc', 'date_asc', 'subject', 'sender'], true) ? $request->get('sort') : 'date_desc',
        ];

        // kolory osób (admin_user_id => color) do etykiet
        $mailUsers = MailUser::with('adminUser:id,first_name,last_name,email')->orderBy('sort')->get();
        $userColors = $mailUsers->pluck('color', 'admin_user_id');

        $query = Message::query()
            ->whereIn('account_id', $accountIds)
            ->with(['category:id,name,color', 'catalog:id,name,color', 'assignedUser:id,first_name,last_name,email']);

        if ($filters['spam']) {
            // Widok „Spam" — tylko maile od zablokowanych nadawców.
            $query->where('is_spam', true);
        } else {
            // Wszystkie pozostałe widoki ukrywają spam.
            $query->where('is_spam', false)->where('is_trashed', $filters['trash']);

            // Wysłane pokazujemy tylko gdy wybrano katalog (np. Wysłane/…); w innych widokach ukryte.
            if (! $filters['catalog_id'] && ! $filters['trash']) {
                $query->where('is_sent', false);
            }
        }

        if ($filters['account_id']) {
            $query->where('account_id', $filters['account_id']);
        }
        if ($filters['user_id']) {
            $query->where('assigned_admin_user_id', $filters['user_id']);
        }
        if ($filters['category_id']) {
            $query->where('category_id', $filters['category_id']);
        }
        if ($filters['catalog_id']) {
            $query->where('catalog_id', $filters['catalog_id']);
        }
        // „Ukryj maile w folderach" — tylko maile BEZ przypisanego katalogu (nieposortowane).
        if ($filters['unfiled']) {
            $query->whereNull('catalog_id');
        }
        // liczniki kolorów (kwadraciki-filtry) — w bieżącym widoku (konto/katalog/kategoria/osoba),
        // ale NIEZALEŻNIE od „tylko nieprzeczytane" i samego koloru, żeby klik zawsze coś pokazywał
        $colorCounts = (clone $query)->reorder()->whereNotNull('color_flag')
            ->selectRaw('color_flag, COUNT(*) as c')->groupBy('color_flag')->pluck('c', 'color_flag');

        // Filtr koloru pokazuje WSZYSTKIE maile w danym kolorze (też przeczytane) — pomija „nieprzeczytane".
        if ($filters['unread'] && ! $filters['color']) {
            $query->where('is_read', false);
        }
        if ($filters['color']) {
            $query->where('color_flag', $filters['color']);
        }
        if ($filters['q']) {
            $q = $filters['q'];
            $query->where(function ($w) use ($q) {
                $w->where('subject', 'like', "%{$q}%")
                    ->orWhere('from_email', 'like', "%{$q}%")
                    ->orWhere('from_name', 'like', "%{$q}%");
            });
        }

        // ===== Grupowanie w wątki (konwersacje) =====
        // Strona = lista wątków (po thread_key), porządek wg ostatniego maila / paska sortowania.
        $threadPage = (clone $query)->setEagerLoads([])->reorder()
            ->selectRaw('thread_key, MAX(date) as last_date, COUNT(*) as cnt, SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) as unread_cnt')
            ->groupBy('thread_key')
            ->when($filters['sort'] === 'date_asc', fn ($q) => $q->orderBy('last_date'))
            ->when($filters['sort'] === 'subject', fn ($q) => $q->orderByRaw("COALESCE(NULLIF(MIN(subject), ''), '~') asc"))
            ->when($filters['sort'] === 'sender', fn ($q) => $q->orderByRaw("COALESCE(NULLIF(MIN(from_name), ''), MIN(from_email)) asc"))
            ->when($filters['sort'] === 'date_desc', fn ($q) => $q->orderByDesc('last_date'))
            ->paginate(120)
            ->withQueryString();

        // Maile wątków z bieżącej strony (reprezentant wiersza + lista id do zaznaczania) — bez treści.
        $pageKeys = collect($threadPage->items())->pluck('thread_key')->filter()->values();
        $members = $pageKeys->isEmpty() ? collect() : (clone $query)->reorder()
            ->whereIn('thread_key', $pageKeys->all())
            ->orderByDesc('date')->orderByDesc('id')
            ->get(['id', 'account_id', 'thread_key', 'from_email', 'from_name', 'subject', 'snippet', 'date', 'is_read', 'is_flagged', 'has_attachments', 'color_flag', 'category_id', 'catalog_id', 'assigned_admin_user_id', 'is_sent', 'to_recipients'])
            ->groupBy('thread_key');

        $threadData = collect($threadPage->items())->map(function ($t) use ($members, $userColors) {
            $group = $members[$t->thread_key] ?? collect();
            $rep = $group->first(); // najnowszy w wątku
            if (! $rep) {
                return null;
            }

            return [
                'id'              => $rep->id,
                'thread_key'      => $t->thread_key,
                'ids'             => $group->pluck('id')->all(),
                'drag_id'         => ($group->firstWhere('is_sent', false)?->id) ?? $rep->id,
                'messages'        => ((int) $t->cnt) > 1
                    ? $group->sortBy('date')->values()->map(fn ($m) => [
                        'id'              => $m->id,
                        'from_name'       => $m->is_sent ? 'Ja' : ($m->from_name ?: ($m->from_email ?: '(brak nadawcy)')),
                        'subject'         => $m->subject,
                        'snippet'         => $m->snippet,
                        'date'            => $m->date?->toIso8601String(),
                        'is_read'         => $m->is_read,
                        'has_attachments' => $m->has_attachments,
                        'is_sent'         => $m->is_sent,
                        'color_flag'      => $m->color_flag,
                    ])->all()
                    : [],
                'account_id'      => $rep->account_id,
                'from_email'      => $rep->from_email,
                'from_name'       => $this->threadParticipants($group),
                'subject'         => $rep->subject,
                'snippet'         => $rep->snippet,
                'date'            => $rep->date?->toIso8601String(),
                'count'           => (int) $t->cnt,
                'unread'          => (int) $t->unread_cnt,
                'is_read'         => ((int) $t->unread_cnt) === 0,
                'has_attachments' => $group->contains(fn ($m) => $m->has_attachments),
                'color_flag'      => $rep->color_flag,
                'category'        => $rep->category ? ['id' => $rep->category->id, 'name' => $rep->category->name, 'color' => $rep->category->color] : null,
                'catalog'         => $rep->catalog ? ['id' => $rep->catalog->id, 'name' => $rep->catalog->name, 'color' => $rep->catalog->color] : null,
                'assigned_user'   => $rep->assignedUser ? [
                    'id'    => $rep->assigned_admin_user_id,
                    'name'  => $this->userName($rep->assignedUser),
                    'color' => $userColors[$rep->assigned_admin_user_id] ?? '#9ca3af',
                ] : null,
            ];
        })->filter()->values();

        $messages = [
            'data'          => $threadData,
            'total'         => $threadPage->total(),
            'current_page'  => $threadPage->currentPage(),
            'last_page'     => $threadPage->lastPage(),
            'prev_page_url' => $threadPage->previousPageUrl(),
            'next_page_url' => $threadPage->nextPageUrl(),
        ];

        // liczniki nieprzeczytanych (z pominięciem kosza i spamu)
        $accUnread = Message::query()->whereIn('account_id', $accountIds)->where('is_read', false)->where('is_trashed', false)->where('is_spam', false)
            ->selectRaw('account_id, COUNT(*) as c')->groupBy('account_id')->pluck('c', 'account_id');
        $userUnread = Message::query()->whereIn('account_id', $accountIds)->where('is_read', false)->where('is_trashed', false)->where('is_spam', false)
            ->whereNotNull('assigned_admin_user_id')->selectRaw('assigned_admin_user_id, COUNT(*) as c')
            ->groupBy('assigned_admin_user_id')->pluck('c', 'assigned_admin_user_id');
        $catUnread = Message::query()->whereIn('account_id', $accountIds)->where('is_read', false)->where('is_trashed', false)->where('is_spam', false)
            ->whereNotNull('category_id')->selectRaw('category_id, COUNT(*) as c')->groupBy('category_id')->pluck('c', 'category_id');

        return Inertia::render('ArgoMail/Index', [
            'accounts' => $accounts->map(fn (Account $a) => [
                'id'           => $a->id,
                'label'        => $a->label,
                'email'        => $a->email,
                'color'        => $a->color,
                'is_active'    => $a->is_active,
                'sync_status'  => $a->sync_status,
                'last_sync_at' => $a->last_sync_at?->toIso8601String(),
                'unread'       => (int) ($accUnread[$a->id] ?? 0),
                'signature'    => $a->signature,
            ])->values(),
            'users' => $mailUsers->map(fn (MailUser $mu) => [
                'id'     => $mu->admin_user_id,
                'name'   => $this->userName($mu->adminUser),
                'color'  => $mu->color,
                'unread' => (int) ($userUnread[$mu->admin_user_id] ?? 0),
            ])->values(),
            'categories'  => Category::query()->orderBy('sort')->orderBy('name')->get(['id', 'name', 'color'])
                ->map(fn (Category $c) => [
                    'id'     => $c->id,
                    'name'   => $c->name,
                    'color'  => $c->color,
                    'unread' => (int) ($catUnread[$c->id] ?? 0),
                ]),
            'catalogs'    => $this->catalogTree(),
            'messages'    => $messages,
            'filters'     => $filters,
            'colorCounts' => (object) $colorCounts->toArray(),
            'totalUnread' => (int) $accUnread->sum(),
            'trashUnread' => (int) Message::where('is_trashed', true)->where('is_read', false)->count(),
            'trashTotal'  => (int) Message::where('is_trashed', true)->count(),
            'spamUnread'  => (int) Message::where('is_spam', true)->where('is_read', false)->count(),
            'spamTotal'   => (int) Message::where('is_spam', true)->count(),
        ]);
    }

    /**
     * Strona Argo Mail → Ustawienia (taby: Katalogi, Kategorie, Osoby).
     */
    public function settings(): Response
    {
        $categories = Category::query()->orderBy('sort')->orderBy('name')->withCount('messages')->get()
            ->map(fn (Category $c) => [
                'id'             => $c->id,
                'name'           => $c->name,
                'color'          => $c->color,
                'is_system'      => $c->is_system,
                'messages_count' => $c->messages_count,
            ]);

        $mailUsers = MailUser::with('adminUser:id,first_name,last_name,email')->orderBy('sort')->get()
            ->map(fn (MailUser $mu) => [
                'id'            => $mu->id,
                'admin_user_id' => $mu->admin_user_id,
                'name'          => $this->userName($mu->adminUser),
                'email'         => $mu->adminUser?->email,
                'color'         => $mu->color,
            ]);

        $availableUsers = AdminUser::query()
            ->whereNotIn('id', MailUser::query()->pluck('admin_user_id'))
            ->orderBy('first_name')->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'email'])
            ->map(fn (AdminUser $u) => [
                'id'    => $u->id,
                'name'  => $this->userName($u),
                'email' => $u->email,
            ]);

        $spamSenders = SpamSender::query()->orderBy('from_email')->orderBy('subject_contains')->get()
            ->map(function (SpamSender $s) {
                $email = mb_strtolower((string) $s->from_email);
                $subject = mb_strtolower(trim((string) $s->subject_contains));
                $q = str_starts_with($email, '@')
                    ? Message::whereRaw("SUBSTRING_INDEX(LOWER(from_email), '@', -1) = ?", [ltrim($email, '@')])
                    : Message::whereRaw('LOWER(from_email) = ?', [$email]);
                if ($subject !== '') {
                    $q->whereRaw('LOWER(subject) LIKE ?', ['%'.$subject.'%']);
                }

                return [
                    'id'               => $s->id,
                    'from_email'       => $s->from_email,
                    'subject_contains' => $s->subject_contains ?: null,
                    'count'            => $q->count(),
                ];
            });

        $senderRules = SenderRule::with('catalog:id,name,color')->orderBy('from_email')->get()
            ->map(fn (SenderRule $r) => [
                'id'               => $r->id,
                'from_email'       => $r->from_email,
                'subject_contains' => $r->subject_contains,
                'catalog'          => $r->catalog ? ['id' => $r->catalog->id, 'name' => $r->catalog->name, 'color' => $r->catalog->color] : null,
            ]);

        $threadExcludes = ThreadExclude::query()->orderBy('from_email')->orderBy('subject_contains')->get()
            ->map(function (ThreadExclude $r) {
                $email = mb_strtolower((string) $r->from_email);
                $subject = mb_strtolower(trim((string) $r->subject_contains));
                $q = str_starts_with($email, '@')
                    ? Message::whereRaw("SUBSTRING_INDEX(LOWER(from_email), '@', -1) = ?", [ltrim($email, '@')])
                    : Message::whereRaw('LOWER(from_email) = ?', [$email]);
                if ($subject !== '') {
                    $q->whereRaw('LOWER(subject) LIKE ?', ['%'.$subject.'%']);
                }

                return [
                    'id'               => $r->id,
                    'from_email'       => $r->from_email,
                    'subject_contains' => $r->subject_contains ?: null,
                    'count'            => $q->count(),
                ];
            });

        return Inertia::render('ArgoMail/Settings', [
            'catalogs'       => $this->catalogTree(),
            'categories'     => $categories,
            'users'          => $mailUsers,
            'availableUsers' => $availableUsers,
            'spamSenders'    => $spamSenders,
            'senderRules'    => $senderRules,
            'threadExcludes' => $threadExcludes,
        ]);
    }

    public function showMessage(Message $message): JsonResponse
    {
        if (! $message->is_read) {
            $message->forceFill(['is_read' => true])->save();
        }

        $message->load([
            'attachments:id,message_id,part_index,filename,mime,size',
            'category:id,name,color',
            'catalog:id,name,color',
            'assignedUser:id,first_name,last_name,email',
        ]);

        $userColor = $message->assigned_admin_user_id
            ? (MailUser::where('admin_user_id', $message->assigned_admin_user_id)->value('color') ?? '#9ca3af')
            : null;

        return response()->json([
            'id'            => $message->id,
            'message_id'    => $message->message_id,
            'subject'       => $message->subject,
            'from_email'    => $message->from_email,
            'from_name'     => $message->from_name,
            'to'            => $message->to_recipients ?? [],
            'cc'            => $message->cc_recipients ?? [],
            'date'          => $message->date?->toIso8601String(),
            'is_sent'       => $message->is_sent,
            'has_attachments' => $message->has_attachments,
            'body_html'     => $message->body_html,
            'body_text'     => $message->body_text,
            'category'      => $message->category ? ['id' => $message->category->id, 'name' => $message->category->name, 'color' => $message->category->color] : null,
            'catalog_id'    => $message->catalog_id,
            'catalog'       => $message->catalog ? ['id' => $message->catalog->id, 'name' => $message->catalog->name, 'color' => $message->catalog->color] : null,
            'assigned_user' => $message->assignedUser ? ['id' => $message->assigned_admin_user_id, 'name' => $this->userName($message->assignedUser), 'color' => $userColor] : null,
            'attachments'   => $message->attachments->map(fn (Attachment $a) => [
                'id'       => $a->id,
                'filename' => $a->filename,
                'mime'     => $a->mime,
                'size'     => $a->size,
            ]),
        ]);
    }

    /**
     * Cała konwersacja (wątek) dla danego maila — wszystkie maile o tym samym thread_key,
     * w tym samym widoku (kosz/spam co otwarty mail), od najstarszego. Oznacza wątek jako przeczytany.
     */
    public function showThread(Message $message): JsonResponse
    {
        $query = Message::query();
        if ($message->thread_key) {
            $query->where('thread_key', $message->thread_key)
                ->where('is_trashed', $message->is_trashed)
                ->where('is_spam', $message->is_spam);
        } else {
            $query->whereKey($message->id);
        }

        $messages = $query->orderBy('date')->orderBy('id')
            ->with([
                'attachments:id,message_id,part_index,filename,mime,size',
                'category:id,name,color',
                'catalog:id,name,color',
                'assignedUser:id,first_name,last_name,email',
            ])->get();

        // oznacz całą rozmowę jako przeczytaną
        $unreadIds = $messages->where('is_read', false)->pluck('id');
        if ($unreadIds->isNotEmpty()) {
            Message::whereIn('id', $unreadIds)->update(['is_read' => true]);
        }

        return response()->json([
            'thread_key' => $message->thread_key,
            'subject'    => $message->subject,
            'messages'   => $messages->map(function (Message $m) {
                $userColor = $m->assigned_admin_user_id
                    ? (MailUser::where('admin_user_id', $m->assigned_admin_user_id)->value('color') ?? '#9ca3af')
                    : null;

                return [
                    'id'              => $m->id,
                    'account_id'      => $m->account_id,
                    'message_id'      => $m->message_id,
                    'subject'         => $m->subject,
                    'from_email'      => $m->from_email,
                    'from_name'       => $m->from_name,
                    'to'              => $m->to_recipients ?? [],
                    'cc'              => $m->cc_recipients ?? [],
                    'date'            => $m->date?->toIso8601String(),
                    'is_sent'         => $m->is_sent,
                    'is_read'         => true,
                    'body_html'       => $m->body_html,
                    'body_text'       => $m->body_text,
                    'has_attachments' => $m->has_attachments,
                    'color_flag'      => $m->color_flag,
                    'catalog_id'      => $m->catalog_id,
                    'catalog'         => $m->catalog ? ['id' => $m->catalog->id, 'name' => $m->catalog->name, 'color' => $m->catalog->color] : null,
                    'category'        => $m->category ? ['id' => $m->category->id, 'name' => $m->category->name, 'color' => $m->category->color] : null,
                    'assigned_user'   => $m->assignedUser ? ['id' => $m->assigned_admin_user_id, 'name' => $this->userName($m->assignedUser), 'color' => $userColor] : null,
                    'attachments'     => $m->attachments->map(fn (Attachment $a) => [
                        'id'       => $a->id,
                        'filename' => $a->filename,
                        'mime'     => $a->mime,
                        'size'     => $a->size,
                    ]),
                ];
            }),
        ]);
    }

    /**
     * Przypisanie wiadomości do osoby (+ opcjonalna reguła „na stałe" dla nadawcy).
     */
    public function assignUser(Request $request, Message $message): JsonResponse
    {
        $data = $request->validate([
            'user_id'   => ['nullable', 'integer', 'exists:admin_users,id'],
            'permanent' => ['nullable', 'boolean'],
        ]);

        $userId = $data['user_id'] ?? null;
        $message->forceFill(['assigned_admin_user_id' => $userId])->save();

        if (! empty($data['permanent']) && $message->from_email) {
            SenderRule::updateOrCreate(
                ['from_email' => mb_strtolower(trim($message->from_email)), 'subject_contains' => ''],
                ['assigned_admin_user_id' => $userId]
            );
        }

        $user = $userId ? AdminUser::find($userId) : null;
        $color = $userId ? (MailUser::where('admin_user_id', $userId)->value('color') ?? '#9ca3af') : null;

        return response()->json([
            'ok'            => true,
            'assigned_user' => $user ? ['id' => $user->id, 'name' => $this->userName($user), 'color' => $color] : null,
        ]);
    }

    public function assignCategory(Request $request, Message $message): JsonResponse
    {
        $data = $request->validate([
            'category_id' => ['nullable', 'integer', 'exists:mail_categories,id'],
        ]);

        $message->forceFill([
            'category_id'    => $data['category_id'] ?? null,
            'categorized_by' => ! empty($data['category_id']) ? 'manual' : null,
        ])->save();

        $category = $message->category_id ? Category::find($message->category_id) : null;

        return response()->json([
            'ok'       => true,
            'category' => $category ? ['id' => $category->id, 'name' => $category->name, 'color' => $category->color] : null,
        ]);
    }

    public function assignCatalog(Request $request, Message $message): JsonResponse
    {
        $data = $request->validate([
            'catalog_id' => ['nullable', 'integer', 'exists:mail_catalogs,id'],
        ]);

        $message->forceFill(['catalog_id' => $data['catalog_id'] ?? null])->save();

        $catalog = $message->catalog_id ? Catalog::find($message->catalog_id) : null;

        return response()->json([
            'ok'      => true,
            'catalog' => $catalog ? ['id' => $catalog->id, 'name' => $catalog->name, 'color' => $catalog->color] : null,
        ]);
    }

    /**
     * Drag & drop: upuszczenie maila na katalog → reguła „konkretny adres nadawcy → katalog" (na stałe)
     * + przeniesienie WSZYSTKICH dotychczasowych maili z tego adresu do tego katalogu.
     * Działa na DOKŁADNY adres (np. payments-noreply@google.com), nie na całą domenę.
     * Reguły na całą domenę (@domena) tworzy się świadomie w zakładce „Filtry".
     */
    public function fileSenderToCatalog(Request $request, Message $message): JsonResponse
    {
        $data = $request->validate([
            'catalog_id' => ['nullable', 'integer', 'exists:mail_catalogs,id'],
        ]);

        $catalogId = $data['catalog_id'] ?? null; // null = upuszczono na „Wszystkie" → zdejmij powiązanie
        $email = mb_strtolower(trim((string) $message->from_email));

        if ($email !== '') {
            if ($catalogId) {
                // Reguła na KONKRETNY ADRES (w resolveRouting = „wykluczenie", najwyższy priorytet).
                // Ten sam klucz co reguła „osoba na stałe" → scala się w jeden wiersz (adres = osoba + katalog).
                SenderRule::updateOrCreate(
                    ['from_email' => $email, 'subject_contains' => ''],
                    ['catalog_id' => $catalogId]
                );
            } else {
                // „Wszystkie" — zdejmij katalog z reguły adresowej (zostaje, jeśli trzyma przypisaną osobę).
                $rule = SenderRule::where('from_email', $email)->where('subject_contains', '')->first();
                if ($rule) {
                    if ($rule->assigned_admin_user_id) {
                        $rule->forceFill(['catalog_id' => null])->save();
                    } else {
                        $rule->delete();
                    }
                }
            }
            // ustaw/zdejmij katalog dla WSZYSTKICH maili z TEGO ADRESU
            $count = Message::whereRaw('LOWER(from_email) = ?', [$email])->update(['catalog_id' => $catalogId]);
        } else {
            $message->forceFill(['catalog_id' => $catalogId])->save();
            $count = 1;
        }

        $catalog = $catalogId ? Catalog::find($catalogId) : null;

        return response()->json([
            'ok'      => true,
            'count'   => $count,
            'cleared' => $catalogId === null,
            'sender'  => $message->from_name ?: ($email ?: (string) $message->from_email),
            'catalog' => $catalog ? ['id' => $catalog->id, 'name' => $catalog->name] : null,
        ]);
    }

    public function trashMessage(Request $request, Message $message): JsonResponse
    {
        $trashed = $request->boolean('trashed', true);

        $message->forceFill([
            'is_trashed' => $trashed,
            'trashed_at' => $trashed ? now() : null,
        ])->save();

        return response()->json(['ok' => true, 'is_trashed' => $trashed]);
    }

    /**
     * Oznacza NADAWCĘ wiadomości jako spam: dodaje go do listy spamu i ukrywa
     * wszystkie jego maile z głównej skrzynki (is_spam = true). Kolejne maile od niego
     * będą auto-oznaczane przy synchronizacji.
     */
    public function markSpam(Message $message): JsonResponse
    {
        $email = mb_strtolower(trim((string) $message->from_email));
        if ($email === '') {
            return response()->json(['ok' => false, 'message' => 'Brak adresu nadawcy.'], 422);
        }

        SpamSender::firstOrCreate(['from_email' => $email]);
        $count = Message::whereRaw('LOWER(from_email) = ?', [$email])->update(['is_spam' => true]);

        return response()->json(['ok' => true, 'from_email' => $email, 'count' => $count]);
    }

    /**
     * „Nie spam" — usuwa nadawcę tej wiadomości z listy spamu i przywraca jego maile.
     */
    public function unspamMessage(Message $message): JsonResponse
    {
        $email = mb_strtolower(trim((string) $message->from_email));
        if ($email !== '') {
            SpamSender::whereRaw('LOWER(from_email) = ?', [$email])->delete();
            Message::whereRaw('LOWER(from_email) = ?', [$email])->update(['is_spam' => false]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Ręczne dodanie adresu do listy spamu (Ustawienia → Spam).
     */
    public function storeSpamSender(Request $request)
    {
        $data = $request->validate([
            'from_email'       => ['required', 'string', 'max:255'],
            'subject_contains' => ['nullable', 'string', 'max:255'],
        ]);

        $value = mb_strtolower(trim($data['from_email']));
        $subject = mb_strtolower(trim((string) ($data['subject_contains'] ?? '')));
        $isDomain = str_starts_with($value, '@');
        $valid = $isDomain
            ? (bool) preg_match('/^@[^@\s]+\.[^@\s]+$/', $value)
            : filter_var($value, FILTER_VALIDATE_EMAIL) !== false;

        if (! $valid) {
            return back()->withErrors(['from_email' => 'Podaj poprawny adres e-mail lub domenę w formacie @domena.pl']);
        }

        SpamSender::firstOrCreate(['from_email' => $value, 'subject_contains' => $subject]);

        // Oflaguj istniejące maile: nadawca/domena (+ opcjonalnie fragment tytułu).
        $q = $isDomain
            ? Message::whereRaw("SUBSTRING_INDEX(LOWER(from_email), '@', -1) = ?", [ltrim($value, '@')])
            : Message::whereRaw('LOWER(from_email) = ?', [$value]);
        if ($subject !== '') {
            $q->whereRaw('LOWER(subject) LIKE ?', ['%'.$subject.'%']);
        }
        $q->update(['is_spam' => true]);

        return back();
    }

    /**
     * Usunięcie nadawcy z listy spamu (Ustawienia → Spam) — przywraca jego maile.
     */
    public function destroySpamSender(SpamSender $spamSender)
    {
        $value = mb_strtolower((string) $spamSender->from_email);
        $subject = mb_strtolower(trim((string) $spamSender->subject_contains));
        $q = str_starts_with($value, '@')
            ? Message::whereRaw("SUBSTRING_INDEX(LOWER(from_email), '@', -1) = ?", [ltrim($value, '@')])
            : Message::whereRaw('LOWER(from_email) = ?', [$value]);
        if ($subject !== '') {
            $q->whereRaw('LOWER(subject) LIKE ?', ['%'.$subject.'%']);
        }
        $q->update(['is_spam' => false]);
        $spamSender->delete();

        return back();
    }

    /**
     * Zapytanie o maile pasujące do reguły: nadawca (lub @domena) + opcjonalny fragment tytułu.
     */
    private function matchingMessagesQuery(string $value, string $subject)
    {
        $q = str_starts_with($value, '@')
            ? Message::whereRaw("SUBSTRING_INDEX(LOWER(from_email), '@', -1) = ?", [ltrim($value, '@')])
            : Message::whereRaw('LOWER(from_email) = ?', [$value]);
        if ($subject !== '') {
            $q->whereRaw('LOWER(subject) LIKE ?', ['%'.$subject.'%']);
        }

        return $q;
    }

    /**
     * Dodaje regułę „bez grupowania" (nadawca/@domena + opcjonalny fragment tytułu) i ROZGRUPOWUJE
     * istniejące pasujące maile — każdy dostaje unikatowy thread_key (stoi osobno). Np. zamówienia
     * Allegro/Amazon przestają zlepiać się w jeden wątek.
     */
    public function storeThreadExclude(Request $request)
    {
        $data = $request->validate([
            'from_email'       => ['required', 'string', 'max:255'],
            'subject_contains' => ['nullable', 'string', 'max:255'],
        ]);

        $value = mb_strtolower(trim($data['from_email']));
        $subject = mb_strtolower(trim((string) ($data['subject_contains'] ?? '')));
        $isDomain = str_starts_with($value, '@');
        $valid = $isDomain
            ? (bool) preg_match('/^@[^@\s]+\.[^@\s]+$/', $value)
            : filter_var($value, FILTER_VALIDATE_EMAIL) !== false;

        if (! $valid) {
            return back()->withErrors(['from_email' => 'Podaj poprawny adres e-mail lub domenę w formacie @domena.pl']);
        }

        ThreadExclude::firstOrCreate(['from_email' => $value, 'subject_contains' => $subject]);

        // Rozgrupuj istniejące: każdy pasujący mail dostaje unikatowy thread_key.
        $this->matchingMessagesQuery($value, $subject)
            ->select(['id', 'account_id', 'folder_id', 'uid', 'message_id'])
            ->chunkById(500, function ($rows) {
                foreach ($rows as $m) {
                    $base = trim((string) $m->message_id) ?: ($m->account_id.'|'.$m->folder_id.'|'.$m->uid);
                    DB::table('mail_messages')->where('id', $m->id)->update(['thread_key' => 'ng:'.sha1($base)]);
                }
            });

        return back();
    }

    /**
     * Usuwa regułę „bez grupowania" i przelicza thread_key pasujących maili z powrotem na normalny
     * (temat + rozmówca) — maile znów grupują się w wątki.
     */
    public function destroyThreadExclude(ThreadExclude $threadExclude)
    {
        $value = mb_strtolower((string) $threadExclude->from_email);
        $subject = mb_strtolower(trim((string) $threadExclude->subject_contains));

        $this->matchingMessagesQuery($value, $subject)
            ->select(['id', 'subject', 'from_email', 'to_recipients', 'is_sent'])
            ->chunkById(500, function ($rows) {
                foreach ($rows as $m) {
                    DB::table('mail_messages')->where('id', $m->id)
                        ->update(['thread_key' => Message::threadKeyFor($m->subject, $m->counterpartEmail())]);
                }
            });

        $threadExclude->delete();

        return back();
    }

    /**
     * Operacje masowe na zaznaczonych wiadomościach (multi-select).
     */
    public function bulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids'    => ['required', 'array', 'min:1'],
            'ids.*'  => ['integer'],
            'action' => ['required', 'string'],
            'value'  => ['nullable', 'integer'],
        ]);

        $query = Message::query()->whereIn('id', $data['ids']);
        $value = $data['value'] ?? null;

        // SPAM masowo — oznacza NADAWCÓW zaznaczonych maili jako spam (spójnie z pojedynczym „Oznacz jako SPAM"):
        // dodaje ich na listę spamu i ukrywa wszystkie ich maile (per-adres, nie tylko zaznaczone).
        if ($data['action'] === 'spam') {
            $emails = Message::query()->whereIn('id', $data['ids'])
                ->whereNotNull('from_email')
                ->pluck('from_email')
                ->map(fn ($e) => mb_strtolower(trim((string) $e)))
                ->filter()
                ->unique();
            $count = 0;
            foreach ($emails as $em) {
                SpamSender::firstOrCreate(['from_email' => $em]);
                $count += Message::whereRaw('LOWER(from_email) = ?', [$em])->update(['is_spam' => true]);
            }

            return response()->json(['ok' => true, 'count' => $count, 'senders' => $emails->count()]);
        }

        switch ($data['action']) {
            case 'trash':    $query->update(['is_trashed' => true, 'trashed_at' => now()]); break;
            case 'restore':  $query->update(['is_trashed' => false, 'trashed_at' => null]); break;
            case 'read':     $query->update(['is_read' => true]); break;
            case 'unread':   $query->update(['is_read' => false]); break;
            case 'category': $query->update(['category_id' => $value, 'categorized_by' => $value ? 'manual' : null]); break;
            case 'catalog':  $query->update(['catalog_id' => $value]); break;
            case 'user':     $query->update(['assigned_admin_user_id' => $value]); break;
            default:
                return response()->json(['ok' => false, 'message' => 'Nieznana akcja.'], 422);
        }

        return response()->json(['ok' => true, 'count' => count($data['ids'])]);
    }

    /**
     * Kolor-flaga (czerwony/zielony/niebieski) na zaznaczonych wiadomościach.
     */
    public function setColor(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids'   => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'color' => ['nullable', 'string', 'in:red,green,blue,orange'],
        ]);

        Message::query()->whereIn('id', $data['ids'])->update(['color_flag' => $data['color'] ?? null]);

        return response()->json(['ok' => true]);
    }

    /**
     * Wysyła wiadomość (nowa / odpowiedź / przekazanie) przez SMTP konta
     * i zapisuje kopię w katalogu Wysłane/[skrzynka].
     */
    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'account_id'    => ['required', 'integer', 'exists:mail_accounts,id'],
            'to'            => ['required', 'string'],
            'cc'            => ['nullable', 'string'],
            'subject'       => ['nullable', 'string', 'max:255'],
            'body'          => ['nullable', 'string'],
            'in_reply_to'   => ['nullable', 'string'],
            'attachments'   => ['nullable', 'array', 'max:20'],
            'attachments.*' => ['file', 'max:15360'], // 15 MB / plik
        ]);

        $account = Account::findOrFail($data['account_id']);

        $to = $this->parseEmails($data['to']);
        $cc = $this->parseEmails($data['cc'] ?? '');
        if (empty($to)) {
            return response()->json(['ok' => false, 'message' => 'Podaj poprawny adres odbiorcy.'], 422);
        }

        $subject = trim((string) ($data['subject'] ?? ''));
        $rawBody = (string) ($data['body'] ?? '');
        if ($request->boolean('is_html')) {
            $bodyHtml = $rawBody;
            $bodyText = trim(strip_tags($rawBody));
        } else {
            $bodyText = $rawBody;
            $bodyHtml = nl2br(e($rawBody));
        }

        $previousTimeout = ini_get('default_socket_timeout');
        ini_set('default_socket_timeout', '30');

        try {
            $email = (new Email())
                ->from(new Address($account->email, (string) ($account->label ?? $account->email)))
                ->subject($subject)
                ->text($bodyText !== '' ? $bodyText : ' ')
                ->html($bodyHtml !== '' ? $bodyHtml : ' ');

            foreach ($to as $addr) {
                $email->addTo(new Address($addr));
            }
            foreach ($cc as $addr) {
                $email->addCc(new Address($addr));
            }
            if (! empty($data['in_reply_to'])) {
                $email->getHeaders()->addTextHeader('In-Reply-To', $data['in_reply_to']);
                $email->getHeaders()->addTextHeader('References', $data['in_reply_to']);
            }

            foreach ($request->file('attachments', []) as $file) {
                if ($file && $file->isValid()) {
                    $email->attachFromPath($file->getRealPath(), $file->getClientOriginalName(), $file->getMimeType());
                }
            }

            $tls = ($account->smtp_encryption ?? null) === 'ssl';
            $transport = new EsmtpTransport($account->smtp_host, (int) $account->smtp_port, $tls);
            $transport->setUsername($account->username ?: $account->email);
            $transport->setPassword((string) $account->password);
            $transport->send($email);
        } catch (\Throwable $e) {
            ini_set('default_socket_timeout', (string) $previousTimeout);

            return response()->json(['ok' => false, 'message' => 'Wysyłka nie powiodła się: '.mb_substr($e->getMessage(), 0, 200)], 422);
        }
        ini_set('default_socket_timeout', (string) $previousTimeout);

        // Lokalny folder „Wysłane" (spełnia wymóg NOT NULL folder_id; nie jest synchronizowany).
        $sentFolder = Folder::firstOrCreate(
            ['account_id' => $account->id, 'path' => '__SENT_LOCAL'],
            ['name' => 'Wysłane']
        );
        $uid = (int) Message::where('account_id', $account->id)->where('folder_id', $sentFolder->id)->max('uid') + 1;

        Message::create([
            'account_id'      => $account->id,
            'folder_id'       => $sentFolder->id,
            'uid'             => $uid,
            'subject'         => $subject !== '' ? $subject : '(bez tematu)',
            'from_email'      => $account->email,
            'from_name'       => $account->label,
            'to_recipients'   => array_map(fn ($e) => ['email' => $e, 'name' => ''], $to),
            'cc_recipients'   => array_map(fn ($e) => ['email' => $e, 'name' => ''], $cc),
            'date'            => now(),
            'snippet'         => mb_substr(trim(preg_replace('/\s+/', ' ', $bodyText) ?? ''), 0, 200),
            'body_html'       => $bodyHtml,
            'body_text'       => $bodyText,
            'has_attachments' => ! empty($request->file('attachments')),
            'is_read'         => true,
            'is_sent'         => true,
            'thread_key'      => Message::threadKeyFor($subject, $to[0] ?? ''),
            'catalog_id'      => $this->sendCatalogId($account),
        ]);

        return response()->json(['ok' => true]);
    }

    private function sendCatalogId(Account $account): int
    {
        // Root katalog na wysłane = „Wysłane". Stara nazwa „SEND" (sprzed 06.2026) migrowana w locie,
        // żeby nie powstał duplikat — istniejący root zostaje przemianowany i ponownie użyty.
        Catalog::whereNull('parent_id')->where('name', 'SEND')->update(['name' => 'Wysłane']);
        $root = Catalog::firstOrCreate(['parent_id' => null, 'name' => 'Wysłane'], ['sort' => 9000]);
        $sub = Catalog::firstOrCreate(['parent_id' => $root->id, 'name' => (string) $account->label], ['sort' => 0]);

        return $sub->id;
    }

    /**
     * @return array<int, string>
     */
    private function parseEmails(string $raw): array
    {
        $parts = preg_split('/[,;\s]+/', trim($raw)) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p !== '' && filter_var($p, FILTER_VALIDATE_EMAIL)) {
                $out[] = $p;
            }
        }

        return array_values(array_unique($out));
    }

    public function syncAccount(Account $account, MailSyncService $service): JsonResponse
    {
        return response()->json($service->sync($account));
    }

    public function downloadAttachment(Message $message, Attachment $attachment): HttpResponse
    {
        abort_unless((int) $attachment->message_id === (int) $message->id, 404);

        $account = $message->account;
        abort_unless($account !== null, 404);

        @ini_set('memory_limit', '512M'); // webklex parsuje cały MIME w pamięci — 128M FPM bywa za mało → OOM/502
        @set_time_limit(120);

        $previousTimeout = ini_get('default_socket_timeout');
        ini_set('default_socket_timeout', '60');

        try {
            $client = Client::make($account->imapConfig());
            $client->connect();

            $path = $message->folder?->path ?? 'INBOX';
            $imapFolder = $client->getFolderByPath($path);
            abort_unless($imapFolder !== null, 404);

            $imapMessage = $imapFolder->messages()
                ->whereUid($message->uid)
                ->setFetchBody(true)
                ->get()
                ->first();
            abort_unless($imapMessage !== null, 404, 'Nie znaleziono wiadomości w skrzynce.');

            $attachments = $imapMessage->getAttachments();
            $att = $attachments[$attachment->part_index] ?? $attachments->first();
            abort_unless($att !== null, 404, 'Nie znaleziono załącznika.');

            $content = $att->content;
            $client->disconnect();

            return response($content, 200, [
                'Content-Type'        => $attachment->mime ?: ($att->getMimeType() ?: 'application/octet-stream'),
                'Content-Disposition' => 'attachment; filename="'.addslashes($attachment->filename).'"',
            ]);
        } catch (\Throwable $e) {
            report($e); // zapisz przyczynę do storage/logs/laravel.log (diagnoza 502)
            abort(502, 'Nie udało się pobrać załącznika: '.mb_substr($e->getMessage(), 0, 200));
        } finally {
            ini_set('default_socket_timeout', (string) $previousTimeout);
        }
    }

    private function userName(?AdminUser $user): string
    {
        if (! $user) {
            return '—';
        }
        $name = trim(($user->first_name ?? '').' '.($user->last_name ?? ''));

        return $name !== '' ? $name : (string) ($user->email ?? '—');
    }

    /**
     * Etykieta uczestników wątku do listy (jak w Gmailu): unikalne nazwy nadawców,
     * „Ja" dla naszych wysłanych, w kolejności chronologicznej.
     *
     * @param  \Illuminate\Support\Collection<int, Message>  $group
     */
    private function threadParticipants($group): string
    {
        $names = $group->sortBy('date')->map(
            fn ($m) => $m->is_sent ? 'Ja' : ($m->from_name ?: ($m->from_email ?: '(brak nadawcy)'))
        )->filter()->unique()->values();

        return $names->isEmpty() ? '(brak nadawcy)' : $names->implode(', ');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function catalogTree(): array
    {
        $all = Catalog::query()->orderBy('sort')->orderBy('name')->get();

        $unreadDirect = Message::query()->whereNotNull('catalog_id')->where('is_read', false)->where('is_trashed', false)->where('is_spam', false)
            ->selectRaw('catalog_id, COUNT(*) as c')->groupBy('catalog_id')->pluck('c', 'catalog_id');
        $totalDirect = Message::query()->whereNotNull('catalog_id')->where('is_trashed', false)->where('is_spam', false)
            ->selectRaw('catalog_id, COUNT(*) as c')->groupBy('catalog_id')->pluck('c', 'catalog_id');

        $byParent = [];
        foreach ($all as $c) {
            $byParent[(int) ($c->parent_id ?? 0)][] = $c;
        }

        // Rollup: licznik katalogu = własne maile + suma wszystkich podkatalogów (rekurencyjnie).
        $rollUnread = [];
        $rollTotal = [];
        $sum = function (int $id) use (&$sum, $byParent, $unreadDirect, $totalDirect, &$rollUnread, &$rollTotal) {
            $u = (int) ($unreadDirect[$id] ?? 0);
            $t = (int) ($totalDirect[$id] ?? 0);
            foreach ($byParent[$id] ?? [] as $child) {
                [$cu, $ct] = $sum((int) $child->id);
                $u += $cu;
                $t += $ct;
            }
            $rollUnread[$id] = $u;
            $rollTotal[$id] = $t;

            return [$u, $t];
        };
        foreach ($byParent[0] ?? [] as $root) {
            $sum((int) $root->id);
        }

        $out = [];
        $walk = function (int $parentId, int $depth) use (&$walk, &$out, $byParent, $rollUnread, $rollTotal) {
            foreach ($byParent[$parentId] ?? [] as $c) {
                $out[] = [
                    'id'        => $c->id,
                    'name'      => $c->name,
                    'color'     => $c->color,
                    'parent_id' => $c->parent_id,
                    'depth'     => $depth,
                    'unread'    => (int) ($rollUnread[$c->id] ?? 0),
                    'total'     => (int) ($rollTotal[$c->id] ?? 0),
                ];
                $walk((int) $c->id, $depth + 1);
            }
        };
        $walk(0, 0);

        return $out;
    }
}
