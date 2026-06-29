<?php

namespace App\Models\Connect;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasFactory;

    protected $table = 'orders';

    protected $guarded = ['id'];

    protected $casts = [
        'confirmed' => 'boolean',
        'payment_method_cod' => 'boolean',
        'want_invoice' => 'boolean',
        'date_add' => 'datetime',
        'date_confirmed' => 'datetime',
        'date_in_status' => 'datetime',
        'imported_at' => 'datetime',
        'updated_from_api_at' => 'datetime',
        'payment_done' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'delivery_price' => 'decimal:2',
        'custom_extra_fields' => 'array',
        'commission' => 'array',
        'raw_payload' => 'array',
    ];

    public function products(): HasMany
    {
        return $this->hasMany(OrderProduct::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(OrderStatus::class, 'order_status_id', 'baselinker_status_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function baseSettings(): BelongsTo
    {
        return $this->belongsTo(BaseSettings::class, 'base_settings_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function regularInvoices(): HasMany
    {
        return $this->hasMany(Invoice::class)->where('type', 'invoice');
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(Invoice::class)->where('type', 'correction');
    }

    public function getProductsTotalAttribute(): float
    {
        return (float) $this->products->sum(fn ($p) => $p->price_brutto * $p->quantity);
    }

    public function getBalanceDueAttribute(): float
    {
        return (float) $this->total_amount - (float) $this->payment_done;
    }
}
