<?php

namespace App\Notifications;

use App\Models\Integration;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the user an integration stopped working because the provider no
 * longer accepts the stored token — the consent expired, an admin revoked
 * the app, or the password changed. The fix is always the same: open the
 * add-ons page and reauthorize.
 */
class IntegrationReauthorizationRequired extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Integration $integration,
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
        $frontendUrl = rtrim((string) config('app.frontend_url'), '/');

        return (new MailMessage)
            ->subject("Your {$this->integration->provider->label()} connection needs attention")
            ->line("Batayan can no longer reach your {$this->integration->provider->label()} account. Syncing is paused until you reauthorize the connection.")
            ->action('Reauthorize in Add-ons', "{$frontendUrl}/settings/addons")
            ->line('Your settings are kept — reauthorizing picks up where you left off.');
    }

    /**
     * The in-app payload shown in the notification feed.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => 'integration_reauthorization_required',
            'title' => "{$this->integration->provider->label()} needs reauthorization",
            'body' => 'Syncing is paused until you reconnect the account.',
            'provider' => $this->integration->provider->value,
            'url' => '/settings/addons',
        ];
    }
}
