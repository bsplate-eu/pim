<?php

namespace App\Notifications;

use App\Models\AdminUser;
use App\Models\ArgoTask;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class UserMentionedInTaskNotification extends Notification
{
    use Queueable;

    public function __construct(
        public ArgoTask $task,
        public ?AdminUser $mentionedBy = null,
        public ?string $excerpt = null,
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
            'type'              => 'mention',
            'task_id'           => $this->task->id,
            'task_name'         => $this->task->name,
            'project_id'        => $project?->id,
            'project_name'      => $project?->name,
            'mentioned_by_id'   => $this->mentionedBy?->id,
            'mentioned_by_name' => $this->mentionedBy
                ? trim(($this->mentionedBy->first_name ?? '') . ' ' . ($this->mentionedBy->last_name ?? ''))
                : null,
            'excerpt'           => $this->excerpt,
            'url'               => route('crafter.argo-task.tasks.show', $this->task->id),
        ];
    }
}
