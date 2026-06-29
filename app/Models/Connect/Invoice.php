<?php

namespace App\Models\Connect;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    protected $table = 'connect_invoices';

    protected $guarded = ['id'];

    protected $casts = [
        'issue_date' => 'date',
        'sell_date' => 'date',
        'payment_date' => 'date',
        'total_netto' => 'decimal:2',
        'total_brutto' => 'decimal:2',
        'imported_at' => 'datetime',
        'raw_payload' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function baseSettings(): BelongsTo
    {
        return $this->belongsTo(BaseSettings::class, 'base_settings_id');
    }

    public function parentInvoice(): BelongsTo
    {
        return $this->belongsTo(self::class, 'corrected_invoice_id', 'baselinker_invoice_id');
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(self::class, 'corrected_invoice_id', 'baselinker_invoice_id');
    }

    public function scopeInvoices($query)
    {
        return $query->where('type', 'invoice');
    }

    public function scopeCorrections($query)
    {
        return $query->where('type', 'correction');
    }

    public function getDisplayNumberAttribute(): string
    {
        return $this->nr_full ?: ($this->nr ? "#{$this->nr}" : "#{$this->baselinker_invoice_id}");
    }
}
