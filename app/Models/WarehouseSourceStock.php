<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Stan jednej pozycji po stronie zrodla — surowy, przed zestawieniem z PIM.
 */
class WarehouseSourceStock extends Model
{
    protected $table = 'warehouse_source_stock';

    protected $fillable = [
        'source',
        'source_code',
        'name',
        'quantity',
        'synced_at',
    ];

    protected $casts = [
        'quantity' => 'float',
        'synced_at' => 'datetime',
    ];
}
