<?php

namespace App\Notifications;

use App\Mail\OrganizationInviteMail;
use App\Models\Invitation;
use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class OrganizationInvite extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Invitation $invitation,
        public readonly Organization $organization,
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
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): OrganizationInviteMail
    {
        // A Mailable returned from toMail() is sent as-is — the notifiable's
        // mail route is never applied — so the recipient must be set here.
        return (new OrganizationInviteMail($this->invitation, $this->organization))
            ->to($this->invitation->email);
    }
}
