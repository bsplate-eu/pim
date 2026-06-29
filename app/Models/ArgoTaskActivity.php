<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArgoTaskActivity extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'argo_task_activities';

    protected $fillable = [
        'argo_task_id',
        'admin_user_id',
        'action',
        'payload',
    ];

    protected $casts = [
        'payload'    => 'array',
        'created_at' => 'datetime',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(ArgoTask::class, 'argo_task_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_user_id');
    }
}
