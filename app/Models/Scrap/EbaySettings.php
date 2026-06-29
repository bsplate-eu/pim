<?php

namespace App\Models\Scrap;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Ustawienia integracji eBay (Argo Connect → Integracje → Ebay).
 * client_secret szyfrowany w DB — wzorzec jak App\Models\Connect\BaseSettings::api_key.
 */
class EbaySettings extends Model
{
    protected $table = 'scrap_ebay_settings';

    protected $fillable = [
        'label',
        'client_id',
        'client_secret',
        'seller',
        'marketplace',
        'keyword',
        'enabled',
        'last_sync_at',
        'last_sync_count',
        'compare_pricelist_id',
        'compare_vat',
        'target_pricelist_id',
        'oauth_refresh_token',
        'oauth_refresh_expires_at',
        'ru_name',
        'oauth_scopes',
        'ebay_user_id',
        'oauth_connected_at',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'last_sync_at' => 'datetime',
        'last_sync_count' => 'integer',
        'compare_pricelist_id' => 'integer',
        'compare_vat' => 'decimal:2',
        'oauth_refresh_expires_at' => 'datetime',
        'oauth_connected_at' => 'datetime',
    ];

    protected $hidden = ['client_secret', 'oauth_refresh_token'];

    /** refresh_token (long-lived, ~18 mies.) — szyfrowany w DB jak client_secret. */
    public function setOauthRefreshTokenAttribute(?string $value): void
    {
        $this->attributes['oauth_refresh_token'] = ($value === null || $value === '')
            ? null
            : Crypt::encryptString($value);
    }

    public function getOauthRefreshTokenAttribute(?string $value): ?string
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

    /** Konto sprzedawcy autoryzowane przez OAuth (jest refresh-token i jeszcze nie wygasł)? */
    public function isOauthConnected(): bool
    {
        if (empty($this->attributes['oauth_refresh_token'])) {
            return false;
        }

        return $this->oauth_refresh_expires_at === null || $this->oauth_refresh_expires_at->isFuture();
    }

    public function setClientSecretAttribute(?string $value): void
    {
        $this->attributes['client_secret'] = ($value === null || $value === '')
            ? null
            : Crypt::encryptString($value);
    }

    public function getClientSecretAttribute(?string $value): ?string
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

    public function hasCredentials(): bool
    {
        return ! empty($this->client_id) && ! empty($this->attributes['client_secret']);
    }

    public function maskedClientSecret(): ?string
    {
        $v = $this->client_secret;
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
