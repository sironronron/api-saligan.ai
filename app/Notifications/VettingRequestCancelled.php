<?php

namespace App\Notifications;

use App\Models\VettingRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class VettingRequestCancelled extends Notification
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
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => 'vetting_request',
            'title' => 'A vetting request was cancelled',
            'body' => 'A '.$this->vettingRequest->document_type.' request you were offered was cancelled by the submitter.',
            'document_type' => $this->vettingRequest->document_type,
            'status' => 'cancelled',
            'url' => '/lawyer/requests',
        ];
    }
}
