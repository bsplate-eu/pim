<?php

namespace App\Models\Mail;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
}
