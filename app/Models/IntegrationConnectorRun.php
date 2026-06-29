<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationConnectorRun extends Model
{
    protected $fillable = [
        'integration_id',
        'connector',
        'status',
        'trigger_type',
        'progress',
        'total',
        'created_count',
        'updated_count',
        'skipped_count',
        'failed_count',
        'current_item',
        'message',
        'errors',
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

    public function markRunning(): void
    {
        $this->update([
            'status'     => 'running',
            'started_at' => now(),
        ]);
    }

    public function markCompleted(?string $message = null): void
    {
        $this->update([
            'status'      => 'completed',
            'message'     => $message,
            'finished_at' => now(),
        ]);
    }

    public function markFailed(string $message): void
    {
        $this->update([
            'status'      => 'failed',
            'message'     => $message,
            'finished_at' => now(),
        ]);
    }

    public function tick(int $progress, int $total, ?string $currentItem = null): void
    {
        $this->update([
            'progress'     => $progress,
            'total'        => $total,
            'current_item' => $currentItem,
        ]);
    }

    public function incrementCreated(int $count = 1): void
    {
        $this->increment('created_count', $count);
    }

    public function incrementUpdated(int $count = 1): void
    {
        $this->increment('updated_count', $count);
    }

    public function incrementSkipped(int $count = 1): void
    {
        $this->increment('skipped_count', $count);
    }

    public function incrementFailed(int $count = 1): void
    {
        $this->increment('failed_count', $count);
    }

    public function addError(string $identifier, string $error): void
    {
        $errors   = $this->errors ?? [];
        $errors[] = [
            'id'    => $identifier,
            'error' => $error,
            'at'    => now()->toIso8601String(),
        ];

        $this->update([
            'errors'       => $errors,
            'failed_count' => count($errors),
        ]);
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
        $minutes = (int) floor($seconds / 60);
        $secs = $seconds % 60;
        return "{$minutes}m {$secs}s";
    }

    public function scopeRunning($query)
    {
        return $query->where('status', 'running');
    }

    public function scopeForConnector($query, string $connector)
    {
        return $query->where('connector', $connector);
    }
}
