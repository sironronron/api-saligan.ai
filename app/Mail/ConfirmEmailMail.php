<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ConfirmEmailMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $confirmationUrl,
        public readonly string $email,
    ) {
        //
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Confirm your email to get started on Batayan.ai');
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.confirm-email',
            with: [
                'confirmationUrl' => $this->confirmationUrl,
                'email' => $this->email,
            ],
        );
    }
}
