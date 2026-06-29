<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationSyncLog extends Model
{
    protected $table = 'integration_sync_logs';

    protected $fillable = [
        'integration_id',
        'status',
        'progress',
        'total',
        'current_item',
        'message',
        'errors',
        'error_count',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
        'errors'      => 'array',
    ];

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function getProgressPercentAttribute(): int
    {
        if ($this->total <= 0) return 0;
        return (int) min(100, round($this->progress / $this->total * 100));
    }

    public function getDurationAttribute(): ?string
    {
        if (!$this->started_at) return null;
        $end = $this->finished_at ?? now();
        $seconds = $this->started_at->diffInSeconds($end);
        if ($seconds < 60) return "{$seconds}s";
        $minutes = floor($seconds / 60);
        $secs    = $seconds % 60;
        return "{$minutes}m {$secs}s";
    }

    // Shortcut — aktualizuj postęp
    public function tick(int $progress, int $total, ?string $currentItem = null): void
    {
        $this->update([
            'progress'     => $progress,
            'total'        => $total,
            'current_item' => $currentItem,
        ]);
    }

    public function markRunning(): void
    {
        $this->update(['status' => 'running', 'started_at' => now()]);
    }

    public function addError(string $sku, string $error): void
    {
        $errors   = $this->errors ?? [];
        $errors[] = [
            'sku'   => $sku,
            'error' => $error,
            'at'    => now()->format('H:i:s'),
        ];
        $this->update([
            'errors'      => $errors,
            'error_count' => count($errors),
        ]);
    }

    public function markCompleted(?string $message = null): void
    {
        $fresh = $this->fresh();
        $this->update([
            'status'      => 'completed',
            'progress'    => $this->total ?: $this->progress,
            'finished_at' => now(),
            'message'     => $message ?? ("Zsynchronizowano {$fresh->progress} produktów"
                . ($fresh->error_count > 0 ? ", błędy: {$fresh->error_count}" : '.')),
        ]);
    }

    public function markFailed(string $message): void
    {
        $this->update([
            'status'      => 'failed',
            'finished_at' => now(),
            'message'     => $message,
        ]);
    }
}
