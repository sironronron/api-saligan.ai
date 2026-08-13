<?php

namespace App\Mail;

use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class TrialEndingMail extends Mailable
{
    use Queueable, SerializesModels;

    /** The trial is running out of calendar days. */
    public const REASON_DAYS = 'days';

    /** The trial is running out of message allowance. */
    public const REASON_MESSAGES = 'messages';

    public function __construct(
        public readonly Subscription $subscription,
        public readonly string $reason,
        public readonly int $remaining,
    ) {
        //
    }

    /**
     * The subject names the actual constraint. "Your trial is ending soon" is
     * true of both cases and useful in neither — someone with nine days left
     * and two messages needs to read "messages" in the inbox list.
     */
    public function envelope(): Envelope
    {
        $subject = $this->reason === self::REASON_MESSAGES
            ? "{$this->remaining} ".Str::plural('message', $this->remaining).' left on your Saligan.AI trial'
            : "{$this->remaining} ".Str::plural('day', $this->remaining).' left on your Saligan.AI trial';

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $frontendUrl = rtrim((string) config('app.frontend_url'), '/');

        return new Content(
            view: 'emails.trial-ending',
            with: [
                'plan' => $this->subscription->plan,
                'reason' => $this->reason,
                'remaining' => $this->remaining,
                'endsAt' => $this->subscription->trial_ends_at,
                'pricingUrl' => "{$frontendUrl}/pricing",
            ],
        );
    }
}
