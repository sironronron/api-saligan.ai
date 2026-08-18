<?php

namespace App\Notifications;

use App\Models\VettingRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NotarizationScheduled extends Notification
{
    use Queueable;

    public function __construct(public readonly VettingRequest $vettingRequest)
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
        return (new MailMessage)
            ->subject('Your notarization session is scheduled')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('The notary has scheduled your video notarization session.')
            ->line('Scheduled for: '.($this->vettingRequest->session_scheduled_at?->format('F j, Y g:i A') ?: 'To be confirmed'))
            ->line('Join the session using the meeting link on your request page. Have your government ID ready — it is required for identity verification.')
            ->action('Open request', url($this->routeUrl()));
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => 'vetting_request',
            'title' => 'Your notarization session is scheduled',
            'body' => 'Join via the meeting link on your request page. Have your government ID ready.',
            'document_type' => $this->vettingRequest->document_type,
            'status' => 'session_scheduled',
            'url' => $this->routeUrl(),
        ];
    }

    protected function routeUrl(): string
    {
        return "/vetting/{$this->vettingRequest->id}";
    }
}
