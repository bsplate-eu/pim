<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationBlogMapping extends Model
{
    protected $fillable = [
        'integration_id',
        'entity_type',
        'entity_id',
        'external_id',
        'payload_hash',
        'synced_at',
    ];

    protected $casts = [
        'synced_at' => 'datetime',
    ];

    public const ENTITY_AUTHOR   = 'blog_author';
    public const ENTITY_CATEGORY = 'blog_category';
    public const ENTITY_ARTICLE  = 'blog_article';

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function scopeForIntegration($query, int $integrationId)
    {
        return $query->where('integration_id', $integrationId);
    }

    public function scopeForType($query, string $entityType)
    {
        return $query->where('entity_type', $entityType);
    }
}
