<?php

namespace App\Notifications;

use App\Models\VettingRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VettingRequestDeclined extends Notification
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
            ->subject('No lawyer available for your request')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('Unfortunately, no available lawyer matched your '.$this->vettingRequest->document_type.' request within the response window.')
            ->line('You can submit a new request any time, or upload a clearer document summary to help us find a match.')
            ->action('View request', url($this->routeUrl()));
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => 'vetting_request',
            'title' => 'No lawyer matched your request',
            'body' => 'No available lawyer matched your '.$this->vettingRequest->document_type.' request.',
            'document_type' => $this->vettingRequest->document_type,
            'status' => 'declined',
            'url' => $this->routeUrl(),
        ];
    }

    protected function routeUrl(): string
    {
        return "/vetting/{$this->vettingRequest->id}";
    }
}
