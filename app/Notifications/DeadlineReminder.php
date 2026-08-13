<?php

namespace App\Notifications;

use App\Mail\DeadlineReminderMail;
use App\Models\LegalCase;
use App\Models\Todo;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class DeadlineReminder extends Notification
{
    use Queueable;

    public function __construct(
        public readonly LegalCase|Todo $subject,
        public readonly int $days,
    ) {
        //
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): DeadlineReminderMail
    {
        return (new DeadlineReminderMail($this->subject, $this->days))->to($notifiable);
    }

    /**
     * The in-app payload shown in the notification feed. `url` is the
     * client-side route the bell and the notifications page navigate to.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $isCase = $this->subject instanceof LegalCase;

        return [
            'kind' => $isCase ? 'case' : 'task',
            'title' => $this->subject->title,
            'due_date' => $this->subject->due_date?->toDateString(),
            'days' => $this->days,
            'overdue' => $this->days < 0,
            'url' => $isCase ? "/cases/{$this->subject->id}" : '/todos',
        ];
    }
}
