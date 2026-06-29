<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationEntityState extends Model
{
    protected $fillable = [
        'integration_id',
        'connector',
        'entity_type',
        'entity_id',
        'external_id',
        'state',
        'payload_hash',
        'attempts',
        'last_error',
        'synced_at',
    ];

    protected $casts = [
        'synced_at' => 'datetime',
        'attempts'  => 'integer',
    ];

    // States
    public const STATE_PENDING        = 'pending';
    public const STATE_QUEUED         = 'queued';
    public const STATE_PROCESSING     = 'processing';
    public const STATE_SYNCED         = 'synced';
    public const STATE_FAILED         = 'failed';
    public const STATE_PENDING_DELETE = 'pending_delete';

    // Connectors
    public const CONNECTOR_CATALOG_CREATE = 'catalog_create';
    public const CONNECTOR_CATALOG_DELTA  = 'catalog_delta';
    public const CONNECTOR_MEDIA          = 'media';
    public const CONNECTOR_BLOG           = 'blog';
    public const CONNECTOR_ANALYTICS      = 'analytics';

    // Entity types
    public const ENTITY_PRODUCT       = 'product';
    public const ENTITY_CATEGORY      = 'category';
    public const ENTITY_BLOG_ARTICLE  = 'blog_article';
    public const ENTITY_BLOG_CATEGORY = 'blog_category';
    public const ENTITY_BLOG_AUTHOR   = 'blog_author';

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function markSynced(string $payloadHash, ?string $externalId = null): void
    {
        $data = [
            'state'        => self::STATE_SYNCED,
            'payload_hash' => $payloadHash,
            'synced_at'    => now(),
            'last_error'   => null,
        ];

        if ($externalId !== null) {
            $data['external_id'] = $externalId;
        }

        $this->update($data);
    }

    public function markFailed(string $error): void
    {
        $this->update([
            'state'      => self::STATE_FAILED,
            'attempts'   => $this->attempts + 1,
            'last_error' => $error,
        ]);
    }

    public function markProcessing(): void
    {
        $this->update(['state' => self::STATE_PROCESSING]);
    }

    public function scopeForConnector($query, string $connector)
    {
        return $query->where('connector', $connector);
    }

    public function scopePending($query)
    {
        return $query->where('state', self::STATE_PENDING);
    }

    public function scopeSynced($query)
    {
        return $query->where('state', self::STATE_SYNCED);
    }

    public function scopeFailed($query)
    {
        return $query->where('state', self::STATE_FAILED);
    }
}
