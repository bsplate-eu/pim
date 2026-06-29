<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ArgoTask extends Model
{
    protected $table = 'argo_tasks';

    protected $fillable = [
        'argo_project_id',
        'name',
        'description',
        'content',
        'kanban_column',
        'priority',
        'labels',
        'due_date',
        'position',
        'deployment_status',
        'edycja_admin',
    ];

    protected $casts = [
        'labels'       => 'array',
        'due_date'     => 'date',
        'edycja_admin' => 'boolean',
    ];

    public const DEPLOYMENT_STATUSES = [
        'queued',
        'planning',
        'plan_ready',
        'in_progress',
        'audit',
        'awaiting_admin',
        'deployed',
        'rejected',
        'admin_manual',
    ];

    // Konfiguracja kolumn/priorytetów/etykiet jest per-projekt — patrz ArgoProject::columnsList() itp.
    // Wartości domyślne dla nowego projektu: ArgoProject::DEFAULT_COLUMNS / DEFAULT_LABELS / DEFAULT_PRIORITIES.

    public function project(): BelongsTo
    {
        return $this->belongsTo(ArgoProject::class, 'argo_project_id');
    }

    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(AdminUser::class, 'argo_task_assignees', 'argo_task_id', 'admin_user_id')
            ->withPivot(['assigned_by', 'assigned_at'])
            ->withTimestamps();
    }

    public function activities(): HasMany
    {
        return $this->hasMany(ArgoTaskActivity::class, 'argo_task_id')->orderByDesc('created_at');
    }
}
