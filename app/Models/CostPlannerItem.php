<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CostPlannerItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'cost_planner_month_id',
        'name',
        'amount',
        'status',
        'due_date',
        'category',
        'type',
        'invoice_number',
        'currency',
        'position',
    ];

    protected $casts = [
        'amount'   => 'decimal:2',
        'due_date' => 'date',
        'position' => 'integer',
    ];

    public function month(): BelongsTo
    {
        return $this->belongsTo(CostPlannerMonth::class, 'cost_planner_month_id');
    }
}
