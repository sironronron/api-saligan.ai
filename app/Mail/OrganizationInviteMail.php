<?php

namespace App\Mail;

use App\Models\Invitation;
use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrganizationInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Invitation $invitation,
        public readonly Organization $organization,
    ) {
        //
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "You're invited to join {$this->organization->name} on Saligan.AI",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        $frontendUrl = rtrim((string) config('app.frontend_url'), '/');

        return new Content(
            view: 'emails.invitation',
            with: [
                'inviteUrl' => "{$frontendUrl}/invite/{$this->invitation->token}",
                'expiresInDays' => Invitation::DEFAULT_EXPIRES_DAYS,
                'invitedByName' => $this->invitation->invitedBy?->name ?? 'Your teammate',
            ],
        );
    }
}
