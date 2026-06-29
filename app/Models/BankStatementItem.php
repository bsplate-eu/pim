<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class BankStatementItem extends Model
{
    protected $fillable = [
        'bank_statement_month_id',
        'booking_date', 'description', 'counterparty',
        'amount', 'direction', 'reference', 'raw_row',
        'is_important', 'settlement_group',
        'matched_type', 'matched_id',
        'position',
    ];

    protected $casts = [
        'booking_date'  => 'date',
        'amount'        => 'decimal:2',
        'raw_row'       => 'array',
        'is_important'  => 'boolean',
        'position'      => 'integer',
    ];

    public const GROUPS = ['koszt', 'kasa'];

    public function month(): BelongsTo
    {
        return $this->belongsTo(BankStatementMonth::class, 'bank_statement_month_id');
    }

    public function matched(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'matched_type', 'matched_id');
    }
}
