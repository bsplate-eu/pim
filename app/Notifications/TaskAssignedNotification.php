<?php

namespace App\Notifications;

use App\Models\AdminUser;
use App\Models\ArgoTask;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class TaskAssignedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public ArgoTask $task,
        public ?AdminUser $assignedBy = null,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $project = $this->task->project;

        return [
            'type'             => 'assigned',
            'task_id'          => $this->task->id,
            'task_name'        => $this->task->name,
            'project_id'       => $project?->id,
            'project_name'     => $project?->name,
            'assigned_by_id'   => $this->assignedBy?->id,
            'assigned_by_name' => $this->assignedBy
                ? trim(($this->assignedBy->first_name ?? '') . ' ' . ($this->assignedBy->last_name ?? ''))
                : null,
            'url'              => route('crafter.argo-task.tasks.show', $this->task->id),
        ];
    }
}
