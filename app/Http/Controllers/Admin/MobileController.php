<?php

namespace App\Http\Controllers\Admin;

use App\Models\Mail\Account;
use App\Models\Mail\Catalog;
use App\Models\Mail\Category;
use App\Models\Mail\Message;
use App\Models\WarehouseReservation;
use App\Models\WarehouseSheetRow;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * ARGO Mobile (PWA) — uproszczony shell na telefon.
 * Reużywa istniejące modele (Mail\Message, ArgoTask przez AdminUser::assignedTasks,
 * notyfikacje Laravela) i zwraca lekkie propsy dla stron Mobile/*.
 */
class MobileController extends Controller
{
    public function home(Request $request)
    {
        $user = $request->user();

        return Inertia::render('Mobile/Home', [
            'counts' => [
                'mailUnread'    => (int) $this->inboxQuery()->where('is_read', false)->count(),
                'warehouse'     => (int) WarehouseSheetRow::where('sheet', WarehouseSheetRow::DEFAULT_SHEET)->count(),
                'notifications' => $user ? (int) $user->unreadNotifications()->count() : 0,
            ],
            'userName' => $user ? trim($user->first_name . ' ' . $user->last_name) : '',
        ]);
    }

    /**
     * Magazyn na telefonie — wyszukiwarka po kodzie, jeden ekran.
     *
     * Cala inwentura leci na telefon od razu i filtruje sie lokalnie: w hali
     * zasieg potrafi znikac, a szukanie kodu bez rundy do serwera dziala tez
     * wtedy. Wiersz jest plaski i bez pustych par Miejsce/ilosc, wiec to
     * kilkadziesiat kilobajtow, nie megabajty.
     *
     * Zrodlem jest arkusz inwentury (komenda `warehouse:import-sheet`), a nie
     * zywy stan z Subiekta — stad `importedAt` na ekranie: lepiej pokazac date
     * danych, niz udawac, ze sa biezace.
     */
    public function warehouse(Request $request)
    {
        $rows = WarehouseSheetRow::where('sheet', WarehouseSheetRow::DEFAULT_SHEET)
            ->orderBy('product_code')
            ->get();

        // Rezerwacje ida OBOK ilosci, nie zamiast nich: ilosc mowi, ile lezy
        // na polce, rezerwacja ile z tego jest juz komus obiecane.
        $reservations = WarehouseReservation::active()
            ->where('source', 'sheet')
            ->orderBy('id')
            ->get()
            ->groupBy('source_code');

        return Inertia::render('Mobile/Warehouse', [
            'sheet'      => WarehouseSheetRow::DEFAULT_SHEET,
            'importedAt' => $rows->max('updated_at')?->format('Y-m-d H:i'),
            'rows'       => $rows->map(fn (WarehouseSheetRow $row) => [
                'id'     => $row->id,
                'code'   => $row->product_code,
                'total'  => $row->quantity_total,
                'places' => $row->places(),
                'wymiar' => $row->wymiar,
                'waga'   => $row->waga,
                'uwagi'  => $row->uwagi,
                'team'   => $row->steel_team,
                'reservations' => ($reservations[$row->product_code] ?? collect())
                    ->map(fn (WarehouseReservation $item) => [
                        'id'        => $item->id,
                        'user_name' => $item->user_name,
                        'quantity'  => $item->quantity,
                        'label'     => $item->label(),
                    ])->values(),
            ])->values(),
        ]);
    }

    public function mail(Request $request)
    {
        // Widok mobilny czytał maile ze WSZYSTKICH skrzynek — zawężamy do
        // przypisanych użytkownikowi, tak samo jak desktopowy Argo Mail.
        $visibleAccountIds = Account::visibleIdsFor($request->user());

        $filters = [
            // Wybór skrzynki sprawdzamy o widoczność, a nie tylko o istnienie konta:
            // parametr w URL-u to wejście od użytkownika, więc cudza skrzynka ma się
            // tu odbić od ściany, a nie zostać po cichu odfiltrowana niżej.
            'account_id'  => ($id = $request->integer('account_id')) && Account::isVisibleTo($request->user(), $id)
                ? $id
                : null,
            'catalog_id'  => $request->integer('catalog_id') ?: null,
            'category_id' => $request->integer('category_id') ?: null,
            'unread'      => $request->boolean('unread'),
            'color'       => in_array($request->get('color'), ['red', 'green', 'blue', 'orange'], true) ? $request->get('color') : null,
            'q'           => trim((string) $request->get('q')) ?: null,
        ];

        $query = Message::query()
            ->whereIn('account_id', $visibleAccountIds)
            ->where('is_spam', false)
            ->where('is_trashed', false)
            ->with(['catalog:id,name,color', 'category:id,name,color']);

        if ($filters['account_id']) {
            $query->where('account_id', $filters['account_id']);
        }

        // wysłane pokazujemy tylko gdy wybrano katalog (np. SEND/…)
        if (! $filters['catalog_id']) {
            $query->where('is_sent', false);
        } else {
            $query->where('catalog_id', $filters['catalog_id']);
        }
        if ($filters['category_id']) {
            $query->where('category_id', $filters['category_id']);
        }

        // liczniki kolorów w bieżącym zakresie (niezależnie od unread/koloru)
        $colorCounts = (clone $query)->reorder()->whereNotNull('color_flag')
            ->selectRaw('color_flag, COUNT(*) as c')->groupBy('color_flag')->pluck('c', 'color_flag');

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

        $messages = $query->orderByDesc('date')->limit(80)
            ->get(['id', 'from_name', 'from_email', 'subject', 'snippet', 'date', 'is_read', 'has_attachments', 'color_flag', 'catalog_id', 'category_id'])
            ->map(fn (Message $m) => [
                'id'              => $m->id,
                'from'            => $m->from_name ?: $m->from_email,
                'from_email'      => $m->from_email,
                'subject'         => $m->subject,
                'snippet'         => $m->snippet,
                'date'            => $m->date?->toIso8601String(),
                'is_read'         => (bool) $m->is_read,
                'has_attachments' => (bool) $m->has_attachments,
                'color'           => $m->color_flag,
            ]);

        return Inertia::render('Mobile/Mail', [
            'messages'    => $messages,
            'catalogs'    => $this->mailCatalogTree(),
            'categories'  => Category::query()->orderBy('sort')->orderBy('name')->get(['id', 'name', 'color']),
            'colorCounts' => (object) $colorCounts->toArray(),
            'filters'     => $filters,
            'accounts'    => $this->mailAccountsWithUnread($visibleAccountIds, $request->user()),
        ]);
    }

    public function tasks(Request $request)
    {
        $user = $request->user();

        $tasks = $user
            ? $user->assignedTasks()
                ->with('project:id,name,color')
                ->orderByRaw('due_date IS NULL, due_date ASC')
                ->get()
                ->map(fn ($t) => [
                    'id'       => $t->id,
                    'name'     => $t->name,
                    'project'  => $t->project ? ['id' => $t->project->id, 'name' => $t->project->name, 'color' => $t->project->color] : null,
                    'column'   => $t->kanban_column,
                    'priority' => $t->priority,
                    'labels'   => $t->labels ?? [],
                    'due_date' => $t->due_date?->toDateString(),
                ])
                ->values()
            : collect();

        return Inertia::render('Mobile/Tasks', [
            'tasks' => $tasks,
        ]);
    }

    public function notifications(Request $request)
    {
        $user = $request->user();

        $notifications = $user
            ? $user->notifications()
                ->limit(50)
                ->get()
                ->map(fn ($n) => [
                    'id'         => $n->id,
                    'type'       => class_basename($n->type),
                    'data'       => $n->data,
                    'read_at'    => $n->read_at?->toIso8601String(),
                    'created_at' => $n->created_at?->toIso8601String(),
                ])
                ->values()
            : collect();

        return Inertia::render('Mobile/Notifications', [
            'notifications' => $notifications,
            'unreadCount'   => $user ? (int) $user->unreadNotifications()->count() : 0,
        ]);
    }

    public function markNotificationRead(Request $request, string $id)
    {
        $notification = $request->user()->notifications()->where('id', $id)->first();
        if ($notification && is_null($notification->read_at)) {
            $notification->markAsRead();
        }

        return back();
    }

    public function markAllNotificationsRead(Request $request)
    {
        $request->user()->unreadNotifications->markAsRead();

        return back();
    }

    /**
     * Skrzynki widoczne dla użytkownika + licznik nieprzeczytanych na każdej.
     *
     * Licznik jest liczony w tym samym zakresie co lista („odbiorcza": bez kosza,
     * spamu i wysłanych) i CELOWO nie uwzględnia pozostałych filtrów — ma
     * odpowiadać na „gdzie mam coś nowego", więc musi być widoczny także wtedy,
     * gdy patrzysz akurat na inną skrzynkę.
     */
    private function mailAccountsWithUnread(array $visibleAccountIds, $user)
    {
        $unread = Message::query()
            ->whereIn('account_id', $visibleAccountIds)
            ->where('is_read', false)
            ->where('is_spam', false)
            ->where('is_trashed', false)
            ->where('is_sent', false)
            ->selectRaw('account_id, COUNT(*) as c')
            ->groupBy('account_id')
            ->pluck('c', 'account_id');

        return Account::query()->visibleTo($user)->where('is_active', true)
            ->orderBy('id')->get(['id', 'email', 'label'])
            ->map(fn (Account $a) => [
                'id'     => $a->id,
                'email'  => $a->email,
                'label'  => $a->label,
                'unread' => (int) ($unread[$a->id] ?? 0),
            ])
            ->values();
    }

    /** Bazowe zapytanie skrzynki odbiorczej (bez kosza, spamu i wysłanych). */
    private function inboxQuery()
    {
        return Message::query()
            ->where('is_trashed', false)
            ->where('is_spam', false)
            ->where('is_sent', false);
    }

    /** Drzewo katalogów poczty z licznikami nieprzeczytanych (rollup po podkatalogach). */
    private function mailCatalogTree(): array
    {
        $all = Catalog::query()->orderBy('sort')->orderBy('name')->get();

        $unreadDirect = Message::query()->whereNotNull('catalog_id')->where('is_read', false)->where('is_trashed', false)->where('is_spam', false)
            ->selectRaw('catalog_id, COUNT(*) as c')->groupBy('catalog_id')->pluck('c', 'catalog_id');

        $byParent = [];
        foreach ($all as $c) {
            $byParent[(int) ($c->parent_id ?? 0)][] = $c;
        }

        $rollUnread = [];
        $sum = function (int $id) use (&$sum, $byParent, $unreadDirect, &$rollUnread) {
            $u = (int) ($unreadDirect[$id] ?? 0);
            foreach ($byParent[$id] ?? [] as $child) {
                $u += $sum((int) $child->id);
            }
            $rollUnread[$id] = $u;

            return $u;
        };
        foreach ($byParent[0] ?? [] as $root) {
            $sum((int) $root->id);
        }

        $out = [];
        $walk = function (int $parentId, int $depth) use (&$walk, &$out, $byParent, $rollUnread) {
            foreach ($byParent[$parentId] ?? [] as $c) {
                $out[] = [
                    'id'     => $c->id,
                    'name'   => $c->name,
                    'color'  => $c->color,
                    'depth'  => $depth,
                    'unread' => (int) ($rollUnread[$c->id] ?? 0),
                ];
                $walk((int) $c->id, $depth + 1);
            }
        };
        $walk(0, 0);

        return $out;
    }
}
