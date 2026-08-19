<?php

namespace App\Notifications;

use App\Models\TaskComment;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Tells the assignee someone left a comment on a task assigned to them. The
 * payload carries a short excerpt of the comment and a link to the task.
 */
class TaskCommented extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Todo $todo,
        public readonly TaskComment $comment,
        public readonly User $commenter,
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
        $body = $this->comment->body;
        $excerpt = mb_strimwidth($body, 0, 160, '…');

        return [
            'kind' => 'task_comment',
            'title' => 'New comment on your task',
            'body' => $excerpt,
            'task_id' => $this->todo->id,
            'commenter' => $this->commenter->name,
            'url' => "/tasks/{$this->todo->id}",
        ];
    }
}
