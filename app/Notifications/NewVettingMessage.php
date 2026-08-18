<?php

namespace App\Notifications;

use App\Models\VettingMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class NewVettingMessage extends Notification
{
    use Queueable;

    public function __construct(public readonly VettingMessage $message)
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
        $request = $this->message->vettingRequest;

        return [
            'kind' => 'vetting_message',
            'title' => 'New message from '.$this->message->author->name,
            'body' => $this->message->body,
            'document_type' => $request->document_type,
            'url' => $request->assigned_lawyer_id === $notifiable->id
                ? "/lawyer/requests/{$request->id}"
                : "/vetting/{$request->id}",
        ];
    }
}
