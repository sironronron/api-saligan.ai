<?php

namespace App\Notifications;

use App\Models\Integration;
use App\Services\Integrations\IntegrationCatalogue;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the user a capability keeps failing to sync. Sent once the failures
 * repeat, or immediately when the provider answers with a revoked grant.
 */
class IntegrationSyncFailed extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Integration $integration,
        public readonly string $capability,
        public readonly string $reason,
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

        $label = IntegrationCatalogue::capability(
            $this->integration->provider,
            $this->capability,
        )['label'] ?? $this->capability;

        return (new MailMessage)
            ->subject("{$this->integration->provider->label()} sync problem: {$label}")
            ->line("Batayan could not sync \"{$label}\" from your {$this->integration->provider->label()} account.")
            ->line($this->reason)
            ->action('Open Add-ons', "{$frontendUrl}/settings/addons");
    }

    /**
     * The in-app payload shown in the notification feed.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => 'integration_sync_failed',
            'title' => "{$this->integration->provider->label()} sync failed",
            'body' => $this->reason,
            'provider' => $this->integration->provider->value,
            'capability' => $this->capability,
            'url' => '/settings/addons',
        ];
    }
}
