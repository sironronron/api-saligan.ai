<?php

namespace App\Notifications;

use App\Models\Todo;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Tells the assignee they have been given a task. Only dispatched when the
 * assignee resolves to a real organization member (the assignee column holds a
 * display name), and never to the user who did the assigning.
 */
class TaskAssigned extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Todo $todo,
        public readonly User $assignedBy,
    ) {
        //
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * The in-app payload shown in the notification feed.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => 'task_assigned',
            'title' => 'You have been assigned a task',
            'body' => $this->todo->title,
            'task_id' => $this->todo->id,
            'assigned_by' => $this->assignedBy->name,
            'url' => "/tasks/{$this->todo->id}",
        ];
    }
}
