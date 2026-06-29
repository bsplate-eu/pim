<?php

namespace App\Models\Connect;

use Illuminate\Database\Eloquent\Model;

class OrderSyncLog extends Model
{
    protected $table = 'order_sync_logs';

    protected $guarded = ['id'];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'orders_fetched' => 'integer',
        'orders_new' => 'integer',
        'orders_updated' => 'integer',
    ];

    public function getDurationSecondsAttribute(): ?int
    {
        if (! $this->started_at || ! $this->finished_at) {
            return null;
        }
        return $this->started_at->diffInSeconds($this->finished_at);
    }
}
