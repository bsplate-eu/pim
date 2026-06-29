<?php

namespace App\Models\Connect;

use Illuminate\Database\Eloquent\Model;

class OrderStatus extends Model
{
    protected $table = 'order_status_dictionary';

    protected $guarded = ['id'];

    protected $casts = [
        'baselinker_status_id' => 'integer',
    ];
}
