<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationAnalytic extends Model
{
    protected $table = 'integration_analytics';

    protected $fillable = [
        'integration_id',
        'entity_type',
        'entity_id',
        'external_id',
        'date',
        'page_views',
        'unique_views',
    ];

    protected $casts = [
        'date'         => 'date',
        'page_views'   => 'integer',
        'unique_views' => 'integer',
    ];

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function scopeForIntegration($query, int $integrationId)
    {
        return $query->where('integration_id', $integrationId);
    }

    public function scopeForDate($query, string $date)
    {
        return $query->where('date', $date);
    }

    public function scopeForEntity($query, string $entityType, int $entityId)
    {
        return $query->where('entity_type', $entityType)->where('entity_id', $entityId);
    }
}
