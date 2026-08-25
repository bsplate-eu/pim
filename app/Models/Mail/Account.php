<?php

namespace App\Models\Mail;

use App\Models\AdminUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Wpięta skrzynka pocztowa (IMAP do odbioru + SMTP do wysyłki).
 *
 * UWAGA: 'password' i 'oauth_token' są szyfrowane (cast 'encrypted')
 * oraz ukryte ($hidden) — NIGDY nie trafiają do front-endu (Inertia/JSON).
 */
class Account extends Model
{
    use HasFactory;

    public const SYNC_IDLE = 'idle';
    public const SYNC_SYNCING = 'syncing';
    public const SYNC_ERROR = 'error';

    public const AUTH_PASSWORD = 'password';
    public const AUTH_OAUTH2 = 'oauth2';

    /** Uprawnienie omijające przypisania — „widzi wszystkie skrzynki". */
    public const PERMISSION_ALL = 'crafter.mail-account.all';

    protected $table = 'mail_accounts';

    protected $guarded = ['id'];

    protected $hidden = [
        'password',
        'oauth_token',
    ];

    protected $casts = [
        'password'           => 'encrypted',
        'oauth_token'        => 'encrypted',
        'imap_port'          => 'integer',
        'smtp_port'          => 'integer',
        'sync_window_months' => 'integer',
        'sync_sent'          => 'boolean',
        'is_active'          => 'boolean',
        'last_sync_at'       => 'datetime',
    ];

    /**
     * Konfiguracja klienta IMAP (webklex) budowana w locie z danych skrzynki.
     */
    public function imapConfig(): array
    {
        return [
            'host'          => $this->imap_host,
            'port'          => $this->imap_port,
            'encryption'    => $this->imap_encryption ?: false,
            'validate_cert' => true,
            'username'      => $this->username ?: $this->email,
            'password'      => $this->password,
            'protocol'      => 'imap',
        ];
    }

    public function folders(): HasMany
    {
        return $this->hasMany(Folder::class, 'account_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'account_id');
    }

    /**
     * Użytkownicy panelu, którym ta skrzynka została przypisana imiennie.
     * Ma znaczenie tylko dla ról BEZ uprawnienia `crafter.mail-account.all`.
     */
    public function adminUsers(): BelongsToMany
    {
        return $this->belongsToMany(
            AdminUser::class,
            'mail_account_admin_user',
            'mail_account_id',
            'admin_user_id'
        )->withTimestamps();
    }

    /**
     * Skrzynki widoczne dla danego użytkownika.
     *
     * Zasada: uprawnienie `crafter.mail-account.all` = wszystkie skrzynki;
     * bez niego — tylko te przypisane imiennie. Brak użytkownika (kolejki, CLI)
     * traktujemy jak pełny dostęp, bo tam nie ma kogo ograniczać.
     */
    public function scopeVisibleTo(Builder $query, ?AdminUser $user): Builder
    {
        if ($user === null || $user->can(self::PERMISSION_ALL)) {
            return $query;
        }

        return $query->whereIn('id', self::visibleIdsFor($user));
    }

    /**
     * @return array<int, int>
     */
    public static function visibleIdsFor(?AdminUser $user): array
    {
        if ($user === null || $user->can(self::PERMISSION_ALL)) {
            return self::query()->pluck('id')->all();
        }

        return $user->mailAccounts()->pluck('mail_accounts.id')->all();
    }

    public static function isVisibleTo(?AdminUser $user, int $accountId): bool
    {
        if ($user === null || $user->can(self::PERMISSION_ALL)) {
            return true;
        }

        return in_array($accountId, self::visibleIdsFor($user), true);
    }
}
