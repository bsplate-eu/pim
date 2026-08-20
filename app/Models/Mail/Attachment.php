<?php

namespace App\Models\Mail;

use App\Models\CostPlannerItem;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attachment extends Model
{
    use HasFactory;

    protected $table = 'mail_attachments';

    protected $guarded = ['id'];

    protected $casts = [
        'part_index'           => 'integer',
        'size'                 => 'integer',
        'cost_planner_item_id' => 'integer',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'message_id');
    }

    /** Pozycja kosztu w Planerze utworzona z tego załącznika (lub null). */
    public function costPlannerItem(): BelongsTo
    {
        return $this->belongsTo(CostPlannerItem::class, 'cost_planner_item_id');
    }
}
