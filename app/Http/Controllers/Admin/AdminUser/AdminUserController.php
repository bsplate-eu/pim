<?php

namespace App\Http\Controllers\Admin\AdminUser;

use App\Http\Controllers\Admin\Controller;
use App\Http\Requests\Admin\AdminUser\BulkDestroyAdminUserRequest;
use App\Http\Requests\Admin\AdminUser\DestroyAdminUserRequest;
use App\Http\Requests\Admin\AdminUser\ImpersonalLoginAdminUserRequest;
use App\Http\Requests\Admin\AdminUser\IndexAdminUserRequest;
use App\Http\Requests\Admin\AdminUser\StoreAdminUserRequest;
use App\Http\Requests\Admin\AdminUser\UpdateAdminUserRequest;
use App\Models\AdminUser;
use App\Queries\Filters\FuzzyFilter;
use App\Queries\Sorts\SortNullsLast;
use App\Settings\GeneralSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

class AdminUserController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     */
    public function index(IndexAdminUserRequest $request): Response | JsonResponse
    {
        $adminUsersQuery = QueryBuilder::for(AdminUser::class)
            ->allowedFilters([
                AllowedFilter::custom('search', new FuzzyFilter(
                    'id',
                    'first_name',
                    'last_name',
                    'email',
                    'email_verified_at'
                )),
                AllowedFilter::callback('role', fn (Builder $query, $value) => $query->role($value)),
                AllowedFilter::callback('status', function (Builder $query, $value) {
                    if ($value === "pending") {
                        return $query->whereNull("email_verified_at");
                    } else {
                        return $query->whereActive($value)->whereNotNull("email_verified_at");
                    }
                }),
            ])
            ->defaultSort('id')
            ->allowedSorts(['id', 'first_name', 'email', 'email_verified_at', AllowedSort::custom('last_active_at', new SortNullsLast())]);

        if ($request->wantsJson() && $request->get('bulk_select_all')) {
            return response()->json($adminUsersQuery->select(['id'])->pluck('id'));
        }

        $adminUsers = $adminUsersQuery
            ->with('roles')
            ->select(['id', 'first_name', 'last_name', 'email', 'email_verified_at', 'active', 'last_active_at'])
            ->paginate(request()->get('per_page'))
            ->withQueryString();

        return Inertia::render('AdminUser/Index', [
            'adminUsers' => $adminUsers,
            'filterOptions' => [
                'roles' => Role::all()->map->only(['name'])->pluck('name'),
            ],
        ]);
    }

    /**
     * Show the form for creating a new resource.
     *
     */
    public function create(): Response
    {
        $this->authorize('crafter.admin-user.create');
        $roles = Role::all();

        return Inertia::render('AdminUser/Create', [
            'locales' => app(GeneralSettings::class)->available_locales,
            'defaultLocale' => app(GeneralSettings::class)->default_locale,
            'roles' => $roles,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  StoreAdminUserRequest  $request
     */
    public function store(StoreAdminUserRequest $request)
    {
        $validated = $request->validated();

        $adminUser = AdminUser::create($validated);

        $adminUser->roles()->sync([$request->input('role_id')]);

        return redirect()->route('crafter.admin-users.index')->with(['message' => ___('crafter', 'Operation successful')]);
    }

    /**
     * Display the specified resource.
     *
     * @param  AdminUser  $adminUser
     */
    public function show(AdminUser $adminUser)
    {
        $this->authorize('crafter.admin-user.show', $adminUser);

        return Inertia::render('AdminUser/Show', [
            'adminUser' => $adminUser,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param AdminUser $adminUser
     */
    public function edit(AdminUser $adminUser)
    {
        $this->authorize('crafter.admin-user.edit', $adminUser);

        $adminUser->load('roles');

        $roles = Role::all();

        return Inertia::render('AdminUser/Edit', [
            'adminUser' => $adminUser,
            'avatar' => $adminUser->getMedia('avatar'),
            'locales' => app(GeneralSettings::class)->available_locales,
            'roles' => $roles,
        ]);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param UpdateAdminUserRequest $request
     * @param AdminUser $adminUser
     */
    public function update(UpdateAdminUserRequest $request, AdminUser $adminUser)
    {
        $validated = $request->validated();

        $adminUser->update($validated);

        if ($request->input('role_id')) {
            $adminUser->roles()->sync([$request->input('role_id')]);
        }

        if ($request->wantsJson()) {
            return response()->json('success');
        }

        return redirect()->route('crafter.admin-users.index')->with(['message' => ___('crafter', 'Operation successful')]);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param DestroyAdminUserRequest $request
     * @param AdminUser $adminUser
     */
    public function destroy(DestroyAdminUserRequest $request, AdminUser $adminUser)
    {
        $adminUser->delete();

        return redirect()->back()->with(['message' => ___('crafter', 'Operation successful')]);
    }

    /**
     * Resend verification notification for user.
     *
     * @param AdminUser $adminUser
     */
    public function resendEmailVerificationNotification(AdminUser $adminUser)
    {
        if (! $adminUser->hasVerifiedEmail()) {
            if ($adminUser->wasInvited()) {
                // FIXME: refactor mailable class
                AaminUserInvitationController::sendInvitation(
                    email: $adminUser->email,
                    userFullName: Auth::user()->first_name . " " . Auth::user()->last_name,
                );
            } else {
                $adminUser->sendEmailVerificationNotification();
            }
        }

        return redirect()->back()->with(['message' => ___('crafter', 'Operation successful')]);
    }

    /**
     * Bulk destroy users.
     *
     * @param BulkDestroyAdminUserRequest $request
     */
    public function bulkDestroy(BulkDestroyAdminUserRequest $request)
    {
        DB::transaction(function () use ($request) {
            collect($request->validated()['ids'])
                ->chunk(1000)
                ->each(function ($bulkChunk) {
                    AdminUser::whereIn('id', $bulkChunk)->delete();
                });
        });

        return redirect()->back()->with(['message' => ___('crafter', 'Operation successful')]);
    }

    /**
     * Bulk deactivate users.
     * @param BulkDestroyAdminUserRequest $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function bulkDeactivate(BulkDestroyAdminUserRequest $request)
    {
        DB::transaction(function () use ($request) {
            collect($request->validated()['ids'])
                ->chunk(1000)
                ->each(function ($bulkChunk) {
                    AdminUser::whereIn('id', $bulkChunk)->update(['active' => false]);
                });
        });

        return redirect()->back()->with(['message' => ___('crafter', 'Operation successful')]);
    }

    /**
     * Bulk activate users.
     * @param BulkDestroyAdminUserRequest $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function bulkActivate(BulkDestroyAdminUserRequest $request)
    {
        DB::transaction(function () use ($request) {
            collect($request->validated()['ids'])
                ->chunk(1000)
                ->each(function ($bulkChunk) {
                    AdminUser::whereIn('id', $bulkChunk)->update(['active' => true]);
                });
        });

        return redirect()->back()->with(['message' => ___('crafter', 'Operation successful')]);
    }

    public function impersonalLogin(ImpersonalLoginAdminUserRequest $request, AdminUser $adminUser)
    {
        Auth::login($adminUser);

        return redirect()->route('crafter.home');
    }
}
