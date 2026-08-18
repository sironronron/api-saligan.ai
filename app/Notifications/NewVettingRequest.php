<?php

namespace App\Notifications;

use App\Models\User;
use App\Models\VettingRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells an eligible lawyer a new document needs vetting/notarization. The
 * payload carries only a summary — never the document itself — and a link to
 * the request, where the lawyer decides to accept before opening it.
 */
class NewVettingRequest extends Notification
{
    use Queueable;

    public function __construct(public readonly VettingRequest $vettingRequest)
    {
        //
    }

    /**
     * Delivery channels honour the lawyer's notification preferences.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = [];

        $profile = $notifiable instanceof User ? $notifiable->lawyerProfile : null;

        if ($profile === null || $profile->notify_in_app) {
            $channels[] = 'database';
        }

        if ($profile?->notify_email) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $request = $this->vettingRequest;

        return (new MailMessage)
            ->subject('New document requires vetting — '.$request->document_type)
            ->greeting('Hello '.$notifiable->name.',')
            ->line('A new '.($request->includesNotarization() ? 'vetting and notarization' : 'vetting').' request is waiting for a lawyer.')
            ->line('Document type: '.$request->document_type)
            ->line('Summary: '.$request->summary)
            ->line('Jurisdiction: '.($request->jurisdiction ?: 'Nationwide'))
            ->line('Urgency: '.$request->urgency->label())
            ->action('View and respond', url($this->routeUrl()))
            ->line('You have until the request is assigned to another lawyer to respond.');
    }

    /**
     * The in-app payload shown in the notification feed.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $request = $this->vettingRequest;

        return [
            'kind' => 'vetting_request',
            'title' => 'New document requires vetting — '.$request->document_type,
            'body' => $request->summary,
            'document_type' => $request->document_type,
            'jurisdiction' => $request->jurisdiction,
            'urgency' => $request->urgency->value,
            'service_type' => $request->service_type->value,
            'submitted_at' => $request->created_at?->toIso8601String(),
            'url' => $this->routeUrl(),
        ];
    }

    /**
     * The lawyer-side route that owns the accept/decline decision.
     */
    protected function routeUrl(): string
    {
        return "/lawyer/requests/{$this->vettingRequest->id}";
    }
}
