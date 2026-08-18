<?php

namespace App\Notifications;

use App\Models\VettingRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VettingRequestWaiting extends Notification
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
            ->subject('Still looking for a lawyer for your request')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('No available lawyer matched your '.$this->vettingRequest->document_type.' request yet.')
            ->line('Your request stays open and your fee stays safe. We will match you as soon as a lawyer comes online, or you can check again at any time.')
            ->action('View request', url($this->routeUrl()));
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => 'vetting_request',
            'title' => 'Still looking for a lawyer',
            'body' => 'No available lawyer matched your '.$this->vettingRequest->document_type.' request yet. We will keep trying.',
            'document_type' => $this->vettingRequest->document_type,
            'status' => 'waiting',
            'url' => $this->routeUrl(),
        ];
    }

    protected function routeUrl(): string
    {
        return "/vetting/{$this->vettingRequest->id}";
    }
}
