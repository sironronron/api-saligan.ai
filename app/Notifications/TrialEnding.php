<?php

namespace App\Notifications;

use App\Mail\TrialEndingMail;
use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class TrialEnding extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Subscription $subscription,
        public readonly string $reason,
        public readonly int $remaining,
    ) {
        //
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): TrialEndingMail
    {
        return new TrialEndingMail($this->subscription, $this->reason, $this->remaining);
    }
}
