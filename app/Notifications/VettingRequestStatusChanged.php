<?php

namespace App\Notifications;

use App\Enums\VettingRequestStatus;
use App\Models\VettingRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VettingRequestStatusChanged extends Notification
{
    use Queueable;

    public function __construct(
        public readonly VettingRequest $vettingRequest,
        public readonly VettingRequestStatus $status,
    ) {
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
            ->subject('Your vetting request: '.$this->status->label())
            ->greeting('Hello '.$notifiable->name.',')
            ->line('Your '.$this->vettingRequest->document_type.' request is now: '.$this->status->label().'.')
            ->line($this->statusLine())
            ->action('View request', url($this->routeUrl()));
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => 'vetting_request',
            'title' => 'Your request is now '.$this->status->label(),
            'body' => $this->statusLine(),
            'document_type' => $this->vettingRequest->document_type,
            'status' => $this->status->value,
            'url' => $this->routeUrl(),
        ];
    }

    protected function statusLine(): string
    {
        return match ($this->status) {
            VettingRequestStatus::UnderReview => 'The lawyer has started reviewing your document.',
            VettingRequestStatus::Vetted => 'The lawyer has finished vetting your document.',
            VettingRequestStatus::Notarized, VettingRequestStatus::Completed => 'Your document has been completed and is ready to download.',
            default => 'Your request status changed.',
        };
    }

    protected function routeUrl(): string
    {
        return "/vetting/{$this->vettingRequest->id}";
    }
}
