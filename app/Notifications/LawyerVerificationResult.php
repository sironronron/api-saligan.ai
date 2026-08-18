<?php

namespace App\Notifications;

use App\Models\LawyerProfile;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LawyerVerificationResult extends Notification
{
    use Queueable;

    public function __construct(public readonly LawyerProfile $profile)
    {
        //
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $status = $this->profile->verification_status->value;
        $mail = (new MailMessage)
            ->greeting('Hello '.$this->profile->full_name.',');

        return match ($status) {
            'verified' => $mail
                ->subject('Your lawyer registration was approved')
                ->line('Your Batayan lawyer profile has been verified.')
                ->line('You can now turn on availability and start receiving vetting and notarization requests.')
                ->action('Open your dashboard', url('/lawyer')),
            'rejected', 'revoked' => $mail
                ->subject('Your lawyer registration was not approved')
                ->line('Your lawyer registration could not be approved.')
                ->line('Reason: '.($this->profile->verification_reason ?: 'Not provided.'))
                ->line('You can update your documents and re-submit your application at any time.')
                ->action('Re-submit', url('/lawyer/register')),
            'suspended' => $mail
                ->subject('Your lawyer profile has been suspended')
                ->line('Your lawyer profile is currently suspended.')
                ->line('Reason: '.($this->profile->verification_reason ?: 'Not provided.')),
            default => $mail->subject('Lawyer registration status')
                ->line('Your lawyer registration status is '.$status.'.'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $status = $this->profile->verification_status->value;

        return [
            'kind' => 'lawyer_verification',
            'title' => match ($status) {
                'verified' => 'Your lawyer registration was approved',
                'rejected', 'revoked' => 'Your lawyer registration was not approved',
                'suspended' => 'Your lawyer profile has been suspended',
                default => 'Lawyer registration update',
            },
            'body' => $this->profile->verification_reason ?? ($status === 'verified'
                ? 'You can now start receiving vetting and notarization requests.'
                : 'Update your documents and re-submit to try again.'),
            'status' => $status,
            'url' => '/lawyer',
        ];
    }
}
