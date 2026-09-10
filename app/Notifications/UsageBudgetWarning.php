<?php

namespace App\Notifications;

use App\Models\Subscription;
use App\Models\SubscriptionWindow;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class UsageBudgetWarning extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Subscription $subscription,
        public readonly SubscriptionWindow $window,
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

    public function toMail(object $notifiable): MailMessage
    {
        $reset = $this->window->window_end?->format('F j') ?? 'your next billing date';

        return (new MailMessage)
            ->subject('You have used 80% of your Batayan AI allowance')
            ->line("Your workspace has used {$this->window->percentUsed()}% of this month's AI allowance.")
            ->line("AI work pauses when the allowance runs out and resumes on {$reset}. Upgrade any time for a larger allowance.")
            ->action('View usage', url('/settings/billing'));
    }
}
