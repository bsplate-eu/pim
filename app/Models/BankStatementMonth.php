<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankStatementMonth extends Model
{
    protected $fillable = [
        'bank', 'year', 'month', 'label',
        'file_path', 'file_name', 'imported_at',
    ];

    protected $casts = [
        'year'        => 'integer',
        'month'       => 'integer',
        'imported_at' => 'datetime',
    ];

    public const BANKS = ['santander', 'pko'];

    public function items(): HasMany
    {
        return $this->hasMany(BankStatementItem::class)->orderBy('booking_date')->orderBy('id');
    }

    public static function buildLabel(string $bank, int $year, int $month): string
    {
        $names = CostPlannerMonth::MONTH_NAMES;
        return ucfirst($bank) . ' — ' . ($names[$month] ?? $month) . ' ' . $year;
    }
}
