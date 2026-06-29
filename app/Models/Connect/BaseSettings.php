<?php

namespace App\Models\Connect;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

class BaseSettings extends Model
{
    protected $table = 'connect_base_settings';

    protected $fillable = [
        'label',
        'api_key',
        'enabled',
        'sync_from_date',
        'date_filter_type',
        'include_archive',
        'include_unconfirmed',
        'last_sync_at',
        'last_sync_order_id',
        'last_journal_id',
        'last_invoice_id',
        'last_invoice_sync_at',
        'sync_interval_minutes',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'sync_from_date' => 'datetime',
        'date_filter_type' => 'string',
        'include_archive' => 'boolean',
        'include_unconfirmed' => 'boolean',
        'last_sync_at' => 'datetime',
        'last_sync_order_id' => 'integer',
        'last_journal_id' => 'integer',
        'last_invoice_id' => 'integer',
        'last_invoice_sync_at' => 'datetime',
        'sync_interval_minutes' => 'integer',
    ];

    protected $hidden = ['api_key'];

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'base_settings_id');
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(OrderSyncLog::class, 'base_settings_id');
    }

    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }

    public function setApiKeyAttribute(?string $value): void
    {
        $this->attributes['api_key'] = $value === null || $value === ''
            ? null
            : Crypt::encryptString($value);
    }

    public function getApiKeyAttribute(?string $value): ?string
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

    public function hasApiKey(): bool
    {
        return ! empty($this->attributes['api_key']);
    }

    public function maskedApiKey(): ?string
    {
        $key = $this->api_key;
        if (! $key) {
            return null;
        }
        $len = strlen($key);
        if ($len <= 8) {
            return str_repeat('•', $len);
        }
        return substr($key, 0, 4) . str_repeat('•', $len - 8) . substr($key, -4);
    }
}
