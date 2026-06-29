<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationMediaQueueItem extends Model
{
    protected $table = 'integration_media_queue';

    protected $fillable = [
        'integration_id',
        'product_id',
        'media_id',
        'external_product_id',
        'action',
        'priority',
        'source_url',
        'md5_hash',
        'state',
        'attempts',
        'last_error',
        'synced_at',
    ];

    protected $casts = [
        'synced_at' => 'datetime',
        'priority'  => 'integer',
        'attempts'  => 'integer',
    ];

    public const ACTION_UPLOAD  = 'upload';
    public const ACTION_DELETE  = 'delete';
    public const ACTION_REORDER = 'reorder';
    public const ACTION_REPLACE = 'replace';

    public const STATE_PENDING    = 'pending';
    public const STATE_PROCESSING = 'processing';
    public const STATE_SYNCED     = 'synced';
    public const STATE_FAILED     = 'failed';

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function markSynced(): void
    {
        $this->update([
            'state'     => self::STATE_SYNCED,
            'synced_at' => now(),
        ]);
    }

    public function markFailed(string $error): void
    {
        $this->update([
            'state'      => self::STATE_FAILED,
            'attempts'   => $this->attempts + 1,
            'last_error' => $error,
        ]);
    }

    public function scopePending($query)
    {
        return $query->where('state', self::STATE_PENDING);
    }

    public function scopeForIntegration($query, int $integrationId)
    {
        return $query->where('integration_id', $integrationId);
    }
}
