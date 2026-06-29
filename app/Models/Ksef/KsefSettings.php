<?php

namespace App\Models\Ksef;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Ustawienia integracji KSeF per firma (Argo Connect → Integracje → KSeF).
 * auth_token szyfrowany w DB — wzorzec jak App\Models\Scrap\EbaySettings::client_secret.
 */
class KsefSettings extends Model
{
    protected $table = 'ksef_settings';

    protected $fillable = [
        'company',
        'label',
        'nip',
        'environment',
        'auth_token',
        'enabled',
        'last_sync_at',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'last_sync_at' => 'datetime',
    ];

    protected $hidden = ['auth_token'];

    public function setAuthTokenAttribute(?string $value): void
    {
        $this->attributes['auth_token'] = ($value === null || $value === '')
            ? null
            : Crypt::encryptString($value);
    }

    public function getAuthTokenAttribute(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return null;
        }
    }

    public function hasToken(): bool
    {
        return ! empty($this->attributes['auth_token']);
    }

    public function maskedToken(): ?string
    {
        $v = $this->auth_token;
        if (! $v) {
            return null;
        }
        $len = strlen($v);
        if ($len <= 8) {
            return str_repeat('•', $len);
        }
        return substr($v, 0, 4) . str_repeat('•', $len - 8) . substr($v, -4);
    }
}
