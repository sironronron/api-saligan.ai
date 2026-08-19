<?php

namespace App\Services\Integrations;

use App\Enums\IntegrationProvider;
use App\Models\Integration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Subscribes webhook-capable capabilities to their provider's push channel,
 * best-effort.
 *
 * Push beats polling where the provider supports it — Google Drive and
 * Calendar, and Microsoft Graph, all publish change notifications — but a
 * registration needs a publicly reachable HTTPS address, which a dev box does
 * not have. Registration failures are therefore swallowed: the scheduled sync
 * stays as the fallback and the capability keeps working, just on a delay.
 */
class WebhookRegistrar
{
    /** How long a push channel stays registered before the sweep renews it. */
    public const CHANNEL_TTL_DAYS = 7;

    public function __construct(
        protected readonly TokenRefresher $refresher,
    ) {
        //
    }

    /**
     * Ensure a webhook-capable capability has a live push channel. No-op when
     * one is still good; best-effort otherwise.
     */
    public function ensureSubscribed(Integration $integration, string $capability): void
    {
        $definition = IntegrationCatalogue::capability($integration->provider, $capability);

        if (($definition['sync_mode'] ?? null) !== 'webhook') {
            return;
        }

        $state = $integration->capabilityState($capability);
        $expiresAt = $state['webhook_expires_at'] ?? null;

        if ($expiresAt !== null && now()->lt($expiresAt)) {
            return;
        }

        $accessToken = $integration->freshAccessToken($this->refresher);

        if ($accessToken === null) {
            return;
        }

        try {
            match (true) {
                $integration->provider === IntegrationProvider::GoogleWorkspace => $this->subscribeGoogle($integration, $capability, $accessToken),
                default => $this->subscribeMicrosoft($integration, $capability, $accessToken),
            };
        } catch (\Throwable) {
            // No public callback URL, an unverified domain, or a missing
            // permission all land here. Polling carries the capability until
            // push becomes reachable.
        }
    }

    /**
     * Register a Google push channel for the capability.
     */
    protected function subscribeGoogle(Integration $integration, string $capability, string $accessToken): void
    {
        $channelId = 'batayan:'.$integration->id.':'.$capability.':'.Str::lower(Str::random(12));
        $address = $this->callbackUrl('google');

        $endpoint = match ($capability) {
            'drive_import' => 'https://www.googleapis.com/drive/v3/changes/watch',
            'calendar_sync' => 'https://www.googleapis.com/calendar/v3/events/watch',
            default => null,
        };

        if ($endpoint === null) {
            return;
        }

        $response = Http::withToken($accessToken)->post($endpoint, [
            'id' => $channelId,
            'type' => 'web_hook',
            'address' => $address,
            'expiration' => now()->addDays(self::CHANNEL_TTL_DAYS)->getTimestampMs(),
        ]);

        if ($response->successful()) {
            $integration->updateCapabilityState($capability, [
                'webhook_channel_id' => $response->json('id', $channelId),
                'webhook_resource_id' => $response->json('resourceId'),
                'webhook_expires_at' => now()->addDays(self::CHANNEL_TTL_DAYS)->toIso8601String(),
            ]);
        }
    }

    /**
     * Register a Microsoft Graph subscription for the capability.
     */
    protected function subscribeMicrosoft(Integration $integration, string $capability, string $accessToken): void
    {
        $resource = match ($capability) {
            'onedrive_access' => '/me/drive/root',
            'sharepoint_import', 'sharepoint_lists' => '/sites/root/lists',
            default => null,
        };

        if ($resource === null) {
            return;
        }

        $clientState = Str::lower(Str::random(32));

        $response = Http::withToken($accessToken)
            ->baseUrl(config('integrations.microsoft.graph_url'))
            ->post('/subscriptions', [
                'changeType' => 'created,updated,deleted',
                'notificationUrl' => $this->callbackUrl('microsoft'),
                'resource' => $resource,
                'expirationDateTime' => now()->addDays(min(self::CHANNEL_TTL_DAYS, 3))->toIso8601String(),
                'clientState' => $clientState,
            ]);

        if ($response->successful()) {
            $integration->updateCapabilityState($capability, [
                'webhook_subscription_id' => $response->json('id'),
                'webhook_client_state' => $clientState,
                'webhook_expires_at' => now()->addDays(min(self::CHANNEL_TTL_DAYS, 3))->toIso8601String(),
            ]);
        }
    }

    /**
     * The public URL push notifications arrive on.
     */
    protected function callbackUrl(string $provider): string
    {
        return rtrim((string) config('app.url'), '/')."/api/integrations/webhooks/{$provider}";
    }
}
