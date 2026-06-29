<?php

namespace App\Models\Scrap;

use Illuminate\Database\Eloquent\Model;

/**
 * Log zmian wykrytych przy pomiarze (Argo Scope monitoring): new / removed / price_up / price_down.
 */
class ScrapChange extends Model
{
    protected $fillable = [
        'source',
        'type',
        'external_id',
        'title',
        'herstellernummer',
        'old_price',
        'new_price',
        'detected_at',
    ];

    protected $casts = [
        'old_price' => 'decimal:2',
        'new_price' => 'decimal:2',
        'detected_at' => 'datetime',
    ];
}
