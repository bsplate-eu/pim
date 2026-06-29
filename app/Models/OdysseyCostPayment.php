<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OdysseyCostPayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'odyssey_cost_month_id',
        'paid_at',
        'amount',
        'invoice_number',
    ];

    protected $casts = [
        'paid_at' => 'date',
        'amount'  => 'decimal:2',
    ];

    public function month(): BelongsTo
    {
        return $this->belongsTo(OdysseyCostMonth::class, 'odyssey_cost_month_id');
    }
}
