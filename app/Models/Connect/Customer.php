<?php

namespace App\Models\Connect;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    protected $table = 'customers';

    protected $guarded = ['id'];

    protected $casts = [
        'sources' => 'array',
        'orders_count' => 'integer',
        'first_order_at' => 'datetime',
        'last_order_at' => 'datetime',
    ];

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Normalizuje numer telefonu: tylko cyfry + opcjonalny wiodący '+'.
     */
    public static function normalizePhone(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        $trimmed = trim($raw);
        $hasPlus = str_starts_with($trimmed, '+');
        $digits = preg_replace('/\D+/', '', $trimmed);
        if ($digits === '' || $digits === null) {
            return null;
        }
        return ($hasPlus ? '+' : '') . $digits;
    }

    /**
     * Dzieli "Imię Nazwisko" na dwie części (best-effort).
     *
     * @return array{0:?string, 1:?string}
     */
    public static function splitFullName(?string $full): array
    {
        if ($full === null || trim($full) === '') {
            return [null, null];
        }
        $parts = preg_split('/\s+/', trim($full));
        if (count($parts) === 1) {
            return [$parts[0], null];
        }
        // pierwsza część = imię, reszta = nazwisko
        $first = array_shift($parts);
        return [$first, implode(' ', $parts)];
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->full_name
            ?: trim(($this->first_name ?? '') . ' ' . ($this->last_name ?? ''))
            ?: ($this->email ?? $this->phone ?? 'Klient #' . $this->id);
    }
}
