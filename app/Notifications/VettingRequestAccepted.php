<?php

namespace App\Notifications;

use App\Enums\VettingRequestStatus;
use App\Models\VettingRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VettingRequestAccepted extends Notification
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
            ->subject('A lawyer accepted your vetting request')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('Lawyer '.$this->vettingRequest->assignedLawyer?->name.' has accepted your '.$this->vettingRequest->service_type->label().' request.')
            ->line('Document: '.$this->vettingRequest->document_type)
            ->action('View request', url($this->routeUrl()))
            ->line('You can message the lawyer directly on the request page.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => 'vetting_request',
            'title' => 'A lawyer accepted your request',
            'body' => $this->vettingRequest->assignedLawyer?->name.' will review your '.$this->vettingRequest->document_type.'.',
            'document_type' => $this->vettingRequest->document_type,
            'status' => VettingRequestStatus::Accepted->value,
            'url' => $this->routeUrl(),
        ];
    }

    protected function routeUrl(): string
    {
        return "/vetting/{$this->vettingRequest->id}";
    }
}
