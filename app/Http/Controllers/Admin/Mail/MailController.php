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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
        // Tylko skrzynki przypisane użytkownikowi — $accountIds zawęża dalej całą
        // listę maili, liczniki nieprzeczytanych i taby.
        $accounts = Account::query()->visibleTo($request->user())->orderBy('label')->get();
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
            // Widok „Spam" — maile od zablokowanych nadawców, ale BEZ tych wyrzuconych do kosza
            // (inaczej „Do kosza" na zaznaczonych wyglądało jakby nic nie robiło — mail zostawał na liście).
            $query->where('is_spam', true)->where('is_trashed', false);
        } elseif ($filters['trash']) {
            // Widok „Kosz" — wszystko, co wyrzucone, także spam (musi być gdzie odzyskać albo dobić).
            $query->where('is_trashed', true);
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
        // Strona = lista wątków (po thread_key). W KOSZU i SPAMIE grupujemy po `id` (każdy mail osobno),
        // by pokazać WSZYSTKIE maile — inaczej skasowane bez thread_key (SQL zlepia NULL-e w 1 grupę) lub z jednej rozmowy zlepiają się w jeden wiersz.
        $groupCol = ($filters['trash'] || $filters['spam']) ? 'id' : 'thread_key';
        $threadPage = (clone $query)->setEagerLoads([])->reorder()
            ->selectRaw("{$groupCol} as thread_key, MAX(date) as last_date, COUNT(*) as cnt, SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) as unread_cnt")
            ->groupBy($groupCol)
            ->when($filters['sort'] === 'date_asc', fn ($q) => $q->orderBy('last_date'))
            ->when($filters['sort'] === 'subject', fn ($q) => $q->orderByRaw("COALESCE(NULLIF(MIN(subject), ''), '~') asc"))
            ->when($filters['sort'] === 'sender', fn ($q) => $q->orderByRaw("COALESCE(NULLIF(MIN(from_name), ''), MIN(from_email)) asc"))
            ->when($filters['sort'] === 'date_desc', fn ($q) => $q->orderByDesc('last_date'))
            ->paginate(120)
            ->withQueryString();

        // Maile wątków z bieżącej strony (reprezentant wiersza + lista id do zaznaczania) — bez treści.
        $pageKeys = collect($threadPage->items())->pluck('thread_key')->filter()->values();
        $members = $pageKeys->isEmpty() ? collect() : (clone $query)->reorder()
            ->whereIn($groupCol, $pageKeys->all())
            ->orderByDesc('date')->orderByDesc('id')
            ->get(['id', 'account_id', 'thread_key', 'from_email', 'from_name', 'subject', 'snippet', 'date', 'is_read', 'is_flagged', 'has_attachments', 'color_flag', 'category_id', 'catalog_id', 'assigned_admin_user_id', 'is_sent', 'to_recipients'])
            ->groupBy($groupCol);

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
                'is_sent'         => (bool) $rep->is_sent,
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
            // Do przypisywania maila (prawy-klik / akcje masowe) bierzemy WSZYSTKICH aktywnych
            // użytkowników PIM, nie tylko skonfigurowane „Osoby" — inaczej na świeżej instalacji
            // (mail_users puste) menu „Przypisz osobę" jest puste. „Osoby" nadal sterują tabami
            // filtrów i kolorami etykiet; kto jest na tej liście, idzie na górę.
            'assignableUsers' => $this->assignableUsers($userColors),
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
            // Spam liczymy bez tego, co już wyrzucone do kosza — licznik ma się zgadzać z listą.
            'spamUnread'  => (int) Message::where('is_spam', true)->where('is_trashed', false)->where('is_read', false)->count(),
            'spamTotal'   => (int) Message::where('is_spam', true)->where('is_trashed', false)->count(),
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
            'attachments:id,message_id,part_index,filename,mime,size,cost_planner_item_id',
            'category:id,name,color',
            'catalog:id,name,color',
            'assignedUser:id,first_name,last_name,email',
            'account:id,email,label',
        ]);

        $userColor = $message->assigned_admin_user_id
            ? (MailUser::where('admin_user_id', $message->assigned_admin_user_id)->value('color') ?? '#9ca3af')
            : null;

        $costMap = $this->costMapFor($message->attachments->pluck('cost_planner_item_id'));

        return response()->json([
            'id'            => $message->id,
            'message_id'    => $message->message_id,
            'account_id'    => $message->account_id,
            'account_email' => $message->account?->email,
            'subject'       => $message->subject,
            'from_email'    => $message->from_email,
            'from_name'     => $message->from_name,
            'to'            => $message->to_recipients ?? [],
            'cc'            => $message->cc_recipients ?? [],
            'date'          => $message->date?->toIso8601String(),
            'is_sent'       => $message->is_sent,
            'has_attachments' => $message->has_attachments,
            'body_html'     => $this->inlineBody($message),
            'body_text'     => $message->body_text,
            'category'      => $message->category ? ['id' => $message->category->id, 'name' => $message->category->name, 'color' => $message->category->color] : null,
            'catalog_id'    => $message->catalog_id,
            'catalog'       => $message->catalog ? ['id' => $message->catalog->id, 'name' => $message->catalog->name, 'color' => $message->catalog->color] : null,
            'assigned_user' => $message->assignedUser ? ['id' => $message->assigned_admin_user_id, 'name' => $this->userName($message->assignedUser), 'color' => $userColor] : null,
            'attachments'   => $message->attachments->map(fn (Attachment $a) => $this->attachmentArr($a, $costMap)),
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
                'attachments:id,message_id,part_index,filename,mime,size,cost_planner_item_id',
                'category:id,name,color',
                'catalog:id,name,color',
                'assignedUser:id,first_name,last_name,email',
            ])->get();

        $costMap = $this->costMapFor($messages->pluck('attachments')->flatten()->pluck('cost_planner_item_id'));

        // oznacz całą rozmowę jako przeczytaną
        $unreadIds = $messages->where('is_read', false)->pluck('id');
        if ($unreadIds->isNotEmpty()) {
            Message::whereIn('id', $unreadIds)->update(['is_read' => true]);
        }

        return response()->json([
            'thread_key' => $message->thread_key,
            'subject'    => $message->subject,
            'messages'   => $messages->map(function (Message $m) use ($costMap) {
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
                    'body_html'       => $this->inlineBody($m),
                    'body_text'       => $m->body_text,
                    'has_attachments' => $m->has_attachments,
                    'color_flag'      => $m->color_flag,
                    'catalog_id'      => $m->catalog_id,
                    'catalog'         => $m->catalog ? ['id' => $m->catalog->id, 'name' => $m->catalog->name, 'color' => $m->catalog->color] : null,
                    'category'        => $m->category ? ['id' => $m->category->id, 'name' => $m->category->name, 'color' => $m->category->color] : null,
                    'assigned_user'   => $m->assignedUser ? ['id' => $m->assigned_admin_user_id, 'name' => $this->userName($m->assignedUser), 'color' => $userColor] : null,
                    'attachments'     => $m->attachments->map(fn (Attachment $a) => $this->attachmentArr($a, $costMap)),
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

        // TRWAŁE usunięcie zaznaczonych (nie „do kosza"). Ograniczone do skrzynek widocznych dla
        // użytkownika i poprzedzone skasowaniem plików lokalnych załączników — kaskada w bazie
        // czyści tylko wiersze. Operacja NIEODWRACALNA (potwierdzenie jest po stronie front-endu).
        if ($data['action'] === 'delete') {
            $visible = Account::query()->visibleTo(auth()->user())->pluck('id');
            $scoped = Message::query()->whereIn('id', $data['ids'])->whereIn('account_id', $visible);

            $this->purgeLocalAttachmentFiles($scoped->clone());
            $count = $scoped->delete();

            return response()->json(['ok' => true, 'count' => $count]);
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
     * Wyczyść kosz — TRWALE usuwa wszystkie maile w koszu (is_trashed = true) ze wszystkich kont.
     * Załączniki kasują się kaskadowo (FK cascadeOnDelete). Operacja NIEODWRACALNA.
     */
    public function emptyTrash(): JsonResponse
    {
        // Czyszczenie obejmuje wyłącznie skrzynki widoczne dla tego użytkownika.
        $accountIds = Account::query()->visibleTo(auth()->user())->pluck('id');
        $base = Message::query()->whereIn('account_id', $accountIds)->where('is_trashed', true);

        $this->purgeLocalAttachmentFiles($base->clone());
        $count = $base->delete();

        return response()->json(['ok' => true, 'count' => $count]);
    }

    /**
     * Wyczyść spam — TRWALE usuwa wszystkie maile oznaczone jako spam (is_spam = true).
     * Lista zablokowanych nadawców (SpamSender) ZOSTAJE — kolejne maile od nich dalej trafią do spamu.
     * Załączniki kaskadowo. Operacja NIEODWRACALNA.
     */
    public function emptySpam(): JsonResponse
    {
        // Czyszczenie obejmuje wyłącznie skrzynki widoczne dla tego użytkownika.
        $accountIds = Account::query()->visibleTo(auth()->user())->pluck('id');
        $base = Message::query()->whereIn('account_id', $accountIds)->where('is_spam', true);

        $this->purgeLocalAttachmentFiles($base->clone());
        $count = $base->delete();

        return response()->json(['ok' => true, 'count' => $count]);
    }

    /**
     * Usuwa z dysku pliki lokalnych załączników (maile wysłane) dla wiadomości z podanego zapytania,
     * ZANIM skasujemy je z bazy (kaskada DB kasuje same wiersze mail_attachments, ale nie pliki).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Message>  $messageQuery
     */
    private function purgeLocalAttachmentFiles($messageQuery): void
    {
        Attachment::query()
            ->whereNotNull('storage_path')
            ->whereIn('message_id', $messageQuery->select('id'))
            ->pluck('storage_path')
            ->each(fn ($path) => $path ? Storage::disk('local')->delete($path) : null);
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

        // Nie da się wysłać „z cudzej" skrzynki — nawet gdyby ktoś podmienił
        // account_id w żądaniu (middleware mail.account łapie to samo wcześniej).
        $account = Account::query()
            ->visibleTo($request->user())
            ->findOrFail($data['account_id']);

        $to = $this->parseEmails($data['to']);
        $cc = $this->parseEmails($data['cc'] ?? '');
        if (empty($to)) {
            return response()->json(['ok' => false, 'message' => 'Nie rozpoznano adresu w polu „Do". Wpisz sam adres, np. jan@firma.pl.'], 422);
        }
        if ($bad = $this->undeliverableEmails(array_merge($to, $cc))) {
            return response()->json([
                'ok' => false,
                'message' => 'To adres testowy, poczta tam nie dojdzie: '.implode(', ', $bad).'. Wpisz prawdziwy adres odbiorcy.',
            ], 422);
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

        // ── Stopka (podpis) konta ────────────────────────────────────────────
        // Doklejamy ją TU, na backendzie, jako SUROWY HTML — żeby pełny design
        // stopki (tabela, kolory, logo) NIE był obcinany przez edytor TipTap
        // w oknie pisania. Dlatego front (bodyFor) już jej nie wstawia.
        $sig = trim((string) ($account->signature ?? ''));
        if ($sig !== '') {
            $sigIsHtml = (bool) preg_match('/<[a-z][\s\S]*>/iu', $sig);
            $sigHtml   = $sigIsHtml ? $sig : nl2br(e($sig));
            $sigText   = trim($sigIsHtml ? strip_tags($sig) : $sig);

            $bodyHtml = ($bodyHtml !== '' ? $bodyHtml : '').'<br><br>'.$sigHtml;
            if ($sigText !== '') {
                $bodyText = trim($bodyText)."\n\n-- \n".$sigText;
            }
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

        $files = array_values(array_filter($request->file('attachments', []), fn ($f) => $f && $f->isValid()));

        $sentMessage = Message::create([
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
            'has_attachments' => ! empty($files),
            'is_read'         => true,
            'is_sent'         => true,
            'thread_key'      => Message::threadKeyFor($subject, $to[0] ?? ''),
            'catalog_id'      => $this->sendCatalogId($account),
        ]);

        // Zapisz treści załączników lokalnie (mail wysłany nie jest na IMAP → nie da się ich
        // dociągnąć później). part_index tylko dla porządku; pobieranie idzie po storage_path.
        foreach ($files as $idx => $file) {
            $safeName = preg_replace('/[^\w.\-]+/u', '_', $file->getClientOriginalName()) ?: ('zalacznik-'.($idx + 1));
            $stored = $file->storeAs("mail/sent/{$sentMessage->id}", $idx.'_'.$safeName, 'local');

            $sentMessage->attachments()->create([
                'part_index'   => $idx,
                'filename'     => mb_substr($file->getClientOriginalName(), 0, 255),
                'mime'         => $file->getClientMimeType(),
                'size'         => (int) $file->getSize() ?: null,
                'storage_path' => $stored,
            ]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Podpowiedzi adresów e-mail do kompozytora (autouzupełnianie pól Do/DW).
     *
     * Źródło: nadawcy odebranych wiadomości (from_email/from_name) + odbiorcy wysłanych
     * (to_recipients/cc_recipients). Dedup po adresie (lowercase), ranking po częstości użycia.
     */
    public function contacts(Request $request): JsonResponse
    {
        $q = mb_strtolower(trim((string) $request->query('q', '')));

        // Mapa [email => ['email','name','count']]; $add scala duplikaty i uzupełnia brakującą nazwę.
        $contacts = [];
        $add = function (?string $email, ?string $name, int $count = 1) use (&$contacts) {
            $email = mb_strtolower(trim((string) $email));
            if ($email === '' || ! str_contains($email, '@')) {
                return;
            }
            $name = trim((string) $name);
            if (! isset($contacts[$email])) {
                $contacts[$email] = ['email' => $email, 'name' => $name, 'count' => 0];
            } elseif ($contacts[$email]['name'] === '' && $name !== '') {
                $contacts[$email]['name'] = $name;
            }
            $contacts[$email]['count'] += $count;
        };

        // 1) Nadawcy odebranych — grupowane w SQL (od razu ranking, bez ładowania wszystkich maili).
        $from = Message::query()
            ->whereNotNull('from_email')->where('from_email', '!=', '')
            ->where('is_sent', false)->where('is_spam', false);
        if ($q !== '') {
            $from->where(function ($w) use ($q) {
                $w->whereRaw('LOWER(from_email) LIKE ?', ['%'.$q.'%'])
                    ->orWhereRaw('LOWER(from_name) LIKE ?', ['%'.$q.'%']);
            });
        }
        $from->selectRaw('LOWER(from_email) as email, MAX(from_name) as name, COUNT(*) as cnt')
            ->groupBy(DB::raw('LOWER(from_email)'))
            ->orderByDesc('cnt')->limit(50)->get()
            ->each(fn ($r) => $add($r->email, $r->name, (int) $r->cnt));

        // 2) Odbiorcy wysłanych (to + cc) — pola JSON, więc czytane w PHP z próbki ostatnich wysłanych.
        Message::query()->where('is_sent', true)
            ->select(['to_recipients', 'cc_recipients', 'date'])
            ->orderByDesc('date')->limit(500)->get()
            ->each(function ($m) use ($add) {
                foreach (array_merge((array) $m->to_recipients, (array) $m->cc_recipients) as $r) {
                    $add($r['email'] ?? null, $r['name'] ?? null);
                }
            });

        // Filtr po fragmencie dla adresów z wysłanych (odebrane już przefiltrowane w SQL).
        $items = array_values($contacts);
        if ($q !== '') {
            $items = array_values(array_filter(
                $items,
                fn ($c) => str_contains($c['email'], $q) || str_contains(mb_strtolower($c['name']), $q)
            ));
        }
        usort($items, fn ($a, $b) => $b['count'] <=> $a['count']);

        return response()->json([
            'contacts' => array_map(
                fn ($c) => ['email' => $c['email'], 'name' => $c['name']],
                array_slice($items, 0, 8)
            ),
        ]);
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
     * Rozbija surowe pole „Do"/„DW" na listę adresów. Przyjmuje to, co realnie
     * wkleja się z Outlooka/Gmaila, nie tylko goły adres:
     *   jan@firma.pl
     *   Jan Kowalski <jan@firma.pl>
     *   "Kowalski, Jan" <jan@firma.pl>; anna@firma.pl
     *   mailto:jan@firma.pl
     *
     * @return array<int, string>
     */
    private function parseEmails(string $raw): array
    {
        // Kopiuj-wklej z przeglądarki wciąga NBSP/zero-width, które psują podział po spacjach.
        $raw = str_replace(["\u{00A0}", "\u{200B}", "\u{FEFF}"], ' ', $raw);

        $candidates = [];

        // Najpierw adresy w nawiasach — reszta („Jan Kowalski") to nazwa wyświetlana, nie adres.
        if (preg_match_all('/<([^<>]+)>/', $raw, $m)) {
            foreach ($m[1] as $addr) {
                $candidates[] = $addr;
            }
            $raw = preg_replace('/<[^<>]*>/', ' ', $raw) ?? $raw;
        }

        foreach (preg_split('/[,;\s]+/', $raw) ?: [] as $part) {
            $candidates[] = $part;
        }

        $out = [];
        foreach ($candidates as $p) {
            $p = preg_replace('/^\s*mailto:/i', '', $p) ?? $p;
            $p = trim($p, " \t\n\r\0\x0B\"'<>,;.");
            if ($p !== '' && filter_var($p, FILTER_VALIDATE_EMAIL)) {
                $out[] = $p;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Adresy w domenach zarezerwowanych na przykłady (RFC 2606/6761). Mają null MX —
     * poczta tam nigdy nie dojdzie, więc lepiej odrzucić od razu niż czekać na bounce.
     *
     * @param  array<int, string>  $emails
     * @return array<int, string>
     */
    private function undeliverableEmails(array $emails): array
    {
        $domains = ['example.com', 'example.org', 'example.net', 'example.edu', 'localhost'];
        $tlds = ['test', 'invalid', 'localhost', 'example'];

        $bad = [];
        foreach ($emails as $email) {
            $domain = strtolower(substr((string) strrchr($email, '@'), 1));
            $tld = str_contains($domain, '.') ? substr((string) strrchr($domain, '.'), 1) : $domain;
            if (in_array($domain, $domains, true) || in_array($tld, $tlds, true)) {
                $bad[] = $email;
            }
        }

        return array_values(array_unique($bad));
    }

    public function syncAccount(Account $account, MailSyncService $service): JsonResponse
    {
        return response()->json($service->sync($account));
    }

    public function downloadAttachment(Message $message, Attachment $attachment): HttpResponse
    {
        abort_unless((int) $attachment->message_id === (int) $message->id, 404);

        // Załącznik maila wysłanego — treść leży lokalnie (nie ma go na IMAP). Serwuj z dysku.
        if ($attachment->storage_path) {
            abort_unless(Storage::disk('local')->exists($attachment->storage_path), 404, 'Nie znaleziono pliku załącznika.');

            return response(Storage::disk('local')->get($attachment->storage_path), 200, [
                'Content-Type'        => $attachment->mime ?: 'application/octet-stream',
                'Content-Disposition' => 'attachment; filename="'.addslashes($attachment->filename).'"',
            ]);
        }

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

    /**
     * Zwraca body_html z obrazkami inline podmienionymi na URL asynchronicznego endpointu:
     * `src="cid:<Content-ID>"` → `.../messages/{id}/inline?cid=<Content-ID>`. To CZYSTA podmiana
     * (regex) — BEZ dotykania IMAP, więc treść ładuje się natychmiast. Obrazki dociąga i cache'uje
     * dopiero endpoint inlineImage(), asynchronicznie w miarę jak przeglądarka ładuje <img>.
     */
    private function inlineBody(Message $message): ?string
    {
        $html = (string) $message->body_html;
        if ($html === '' || ! preg_match('/src\s*=\s*["\']?cid:/i', $html)) {
            return $message->body_html;
        }

        $mid = $message->id;

        return preg_replace_callback('/src\s*=\s*(["\']?)cid:([^"\'>\s]+)\1/i', function ($m) use ($mid) {
            $cid = str_replace(['<', '>'], '', trim($m[2]));

            return 'src="'.route('crafter.argo-mail.messages.inline', $mid).'?cid='.urlencode($cid).'"';
        }, $html);
    }

    /**
     * Serwuje obrazek inline (użyty w treści przez `cid:`). Najpierw z lokalnego cache; przy pudle
     * dociąga wiadomość RAZ z IMAP i cache'uje wszystkie inline obrazki. Lock chroni przed nawałą
     * równoległych żądań (gdy mail ma kilka obrazków → 1 fetch, reszta z dysku). Wołane
     * asynchronicznie z <img> w treści — NIE blokuje ładowania samej wiadomości.
     */
    public function inlineImage(Message $message, Request $request): HttpResponse
    {
        $cid = str_replace(['<', '>'], '', trim((string) $request->query('cid')));
        abort_if($cid === '', 404);

        if ($att = $this->cachedInline($message, $cid)) {
            return $this->serveInline($att);
        }

        // Pudło w cache — dociągnij RAZ (lock: pierwszy proces cache'uje, reszta czeka i bierze z dysku).
        $lock = Cache::lock("mail-inline-{$message->id}", 60);
        try {
            $lock->block(20);
            $att = $this->cachedInline($message, $cid);
            if (! $att) {
                $this->cacheInlineImages($message);
                $att = $this->cachedInline($message, $cid);
            }
        } catch (\Throwable $e) {
            report($e);
            abort(504, 'Nie udało się pobrać obrazka.');
        } finally {
            optional($lock)->release();
        }

        abort_unless($att, 404, 'Nie znaleziono obrazka.');

        return $this->serveInline($att);
    }

    /** Załącznik inline z lokalnego cache (content_id + istniejący plik), albo null. */
    private function cachedInline(Message $message, string $cid): ?Attachment
    {
        $att = $message->attachments()->where('content_id', $cid)->whereNotNull('storage_path')->first();

        return ($att && Storage::disk('local')->exists($att->storage_path)) ? $att : null;
    }

    private function serveInline(Attachment $att): HttpResponse
    {
        return response(Storage::disk('local')->get($att->storage_path), 200, [
            'Content-Type'        => $att->mime ?: 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="'.addslashes($att->filename).'"',
            'Cache-Control'       => 'private, max-age=86400',
        ]);
    }

    /**
     * Dociąga wiadomość z IMAP RAZ i cache'uje jej obrazki inline (image/* lub disposition=inline):
     * uzupełnia content_id (po part_index) i zapisuje treść na dysk (storage_path).
     */
    private function cacheInlineImages(Message $message): void
    {
        $account = $message->account;
        if (! $account) {
            return;
        }

        @ini_set('memory_limit', '512M'); // webklex parsuje cały MIME w pamięci
        @set_time_limit(120);
        $previousTimeout = ini_get('default_socket_timeout');
        ini_set('default_socket_timeout', '20'); // nie wisimy długo, gdy konto nieosiągalne

        try {
            $client = Client::make($account->imapConfig());
            $client->connect();

            $imapFolder = $client->getFolderByPath($message->folder?->path ?? 'INBOX');
            if (! $imapFolder) {
                return;
            }
            $imapMessage = $imapFolder->messages()->whereUid($message->uid)->setFetchBody(true)->get()->first();
            if (! $imapMessage) {
                return;
            }

            $rows = $message->attachments()->get()->keyBy('part_index');

            foreach ($imapMessage->getAttachments() as $i => $att) {
                $row = $rows->get($i) ?? $rows->values()->get($i);
                if (! $row) {
                    continue;
                }
                $cid = $att->id ? str_replace(['<', '>'], '', (string) $att->id) : null;
                $patch = [];
                if ($cid && $row->content_id !== $cid) {
                    $patch['content_id'] = $cid;
                }
                $isInlineImg = str_starts_with((string) ($att->getMimeType() ?: $row->mime), 'image/')
                    || (($att->disposition ?? '') === 'inline');
                if ($cid && $isInlineImg && ! $row->storage_path) {
                    $safe = preg_replace('/[^\w.\-]+/u', '_', (string) $row->filename) ?: ('inline-'.$i);
                    Storage::disk('local')->put($path = "mail/inline/{$message->id}/{$i}_{$safe}", (string) $att->content);
                    $patch['storage_path'] = $path;
                }
                if ($patch) {
                    $row->forceFill($patch)->save();
                }
            }

            $client->disconnect();
        } finally {
            ini_set('default_socket_timeout', (string) $previousTimeout);
        }
    }

    /**
     * Mapa [id pozycji kosztu => CostPlannerItem z miesiącem] dla załączników wpiętych w koszty.
     *
     * @param  \Illuminate\Support\Collection<int, mixed>  $ids
     * @return \Illuminate\Support\Collection<int, \App\Models\CostPlannerItem>
     */
    private function costMapFor($ids)
    {
        $clean = collect($ids)->filter()->unique()->values();
        if ($clean->isEmpty()) {
            return collect();
        }

        return \App\Models\CostPlannerItem::with('month:id,label')
            ->whereIn('id', $clean->all())
            ->get()
            ->keyBy('id');
    }

    /**
     * Payload załącznika do front-endu (+ info „w kosztach" dla zielonego ptaszka).
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\CostPlannerItem>  $costMap
     * @return array<string, mixed>
     */
    private function attachmentArr(Attachment $a, $costMap): array
    {
        $cost = null;
        if ($a->cost_planner_item_id && $costMap->has($a->cost_planner_item_id)) {
            $ci = $costMap->get($a->cost_planner_item_id);
            $cost = [
                'item_id'     => $ci->id,
                'month_id'    => $ci->cost_planner_month_id,
                'month_label' => $ci->month?->label,
            ];
        }

        return [
            'id'                   => $a->id,
            'filename'             => $a->filename,
            'mime'                 => $a->mime,
            'size'                 => $a->size,
            'cost_planner_item_id' => $a->cost_planner_item_id,
            'cost'                 => $cost,
        ];
    }

    /**
     * Użytkownicy, którym można przypisać maila: wszyscy aktywni użytkownicy PIM.
     * Skonfigurowane „Osoby" (mail_users) idą na górę listy i zachowują swój kolor;
     * pozostali dostają kolor szary (jak w assignUser()).
     *
     * @param  \Illuminate\Support\Collection<int, string|null>  $userColors  admin_user_id => color
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function assignableUsers($userColors)
    {
        return AdminUser::query()
            ->where('active', true)
            ->orderBy('first_name')->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'email'])
            ->map(fn (AdminUser $u) => [
                'id'           => $u->id,
                'name'         => $this->userName($u),
                'color'        => $userColors[$u->id] ?? null,
                'is_mail_user' => $userColors->has($u->id),
            ])
            ->sortByDesc('is_mail_user')
            ->values();
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
                    'collapsed' => (bool) $c->collapsed,
                ];
                $walk((int) $c->id, $depth + 1);
            }
        };
        $walk(0, 0);

        return $out;
    }
}
