<?php

namespace App\Models;

use App\Models\Connect\Order;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OdysseyCostOrderEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'odyssey_cost_month_id',
        'order_id',
        'cost_goods',
        'cost_shipping',
    ];

    protected $casts = [
        'cost_goods'    => 'decimal:2',
        'cost_shipping' => 'decimal:2',
    ];

    public function month(): BelongsTo
    {
        return $this->belongsTo(OdysseyCostMonth::class, 'odyssey_cost_month_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
