<?php

namespace App\Services\Integrations;

use App\Models\Integration;
use App\Models\IntegrationAuditLog;
use App\Notifications\IntegrationSyncFailed;
use App\Services\Integrations\Providers\ProviderSyncGateways;
use Illuminate\Http\Client\RequestException;

/**
 * Runs a capability's sync and keeps its status honest.
 *
 * A sync is the moment a broken grant surfaces: the token refresh fails, the
 * provider answers 401, or the network does. Each failure is written onto the
 * capability (so the card can show it), audited, and — once it repeats —
 * notified, so the user is never left wondering why nothing arrives.
 */
class IntegrationSyncService
{
    /** How many consecutive failures pass before the user is told. */
    public const NOTIFY_AFTER_FAILURES = 2;

    public function __construct(
        protected readonly ProviderSyncGateways $gateways,
        protected readonly TokenRefresher $refresher,
        protected readonly IntegrationAuditLogger $audit,
        protected readonly IntegrationManager $manager,
    ) {
        //
    }

    /**
     * Sync every syncable, effectively-enabled capability on a connection.
     *
     * @return array<string, array{ok: bool, summary: string}>
     */
    public function syncAll(Integration $integration): array
    {
        // A paused connection syncs nothing at all — the scheduler never
        // offers one, and a manual attempt gets the same silence.
        if ($integration->isPaused()) {
            return [];
        }

        $results = [];

        foreach (array_keys(IntegrationCatalogue::capabilities($integration->provider)) as $capability) {
            if (! $this->gateways->for($integration->provider)->isSyncable($capability)) {
                continue;
            }

            if (! $this->manager->isEffectivelyEnabled($integration, $capability)) {
                continue;
            }

            $results[$capability] = $this->syncCapability($integration, $capability);
        }

        return $results;
    }

    /**
     * Sync one capability and record what happened.
     *
     * @return array{ok: bool, summary: string}
     */
    public function syncCapability(Integration $integration, string $capability): array
    {
        $gateway = $this->gateways->for($integration->provider);

        if (! $gateway->isSyncable($capability)) {
            return ['ok' => true, 'summary' => 'This capability syncs on demand.'];
        }

        // Always work from the row as stored: syncs run from queues, webhooks,
        // and the scheduler, and a stale in-memory copy of `capabilities`
        // would clobber whatever another writer stored a moment ago.
        $integration = $integration->fresh() ?? $integration;

        if ($integration->isPaused()) {
            return ['ok' => false, 'summary' => 'The integration is paused.'];
        }

        if (! $this->manager->isEffectivelyEnabled($integration, $capability)) {
            return ['ok' => false, 'summary' => 'The capability is not enabled.'];
        }

        $integration->updateCapabilityState($capability, ['sync_status' => 'syncing']);

        $accessToken = $integration->freshAccessToken($this->refresher);

        if ($accessToken === null) {
            $integration = $integration->fresh() ?? $integration;
            $integration->updateCapabilityState($capability, [
                'sync_status' => 'error',
                'last_error' => 'Permission revoked — please reauthorize.',
            ]);

            return ['ok' => false, 'summary' => 'Permission revoked — please reauthorize.'];
        }

        try {
            $result = $gateway->sync($integration->fresh() ?? $integration, $capability, $accessToken);
        } catch (\Throwable $e) {
            return $this->recordFailure($integration->fresh() ?? $integration, $capability, $e);
        }

        $integration = $integration->fresh() ?? $integration;
        $integration->updateCapabilityState($capability, [
            'sync_status' => 'idle',
            'last_synced_at' => now()->toIso8601String(),
            'last_error' => null,
            'consecutive_failures' => 0,
        ]);

        $integration->forceFill(['last_synced_at' => now()])->save();

        return ['ok' => true, 'summary' => $result['summary']];
    }

    /**
     * Write a sync failure onto the capability, audit it, and notify once it
     * starts repeating — a single blip is not worth an email.
     *
     * @return array{ok: bool, summary: string}
     */
    protected function recordFailure(Integration $integration, string $capability, \Throwable $error): array
    {
        $message = $this->friendlyMessage($error);
        $state = $integration->capabilityState($capability);
        $failures = (int) ($state['consecutive_failures'] ?? 0) + 1;

        $integration->updateCapabilityState($capability, [
            'sync_status' => 'error',
            'last_error' => $message,
            'consecutive_failures' => $failures,
        ]);

        $this->audit->log(
            $integration->user,
            $integration->provider,
            IntegrationAuditLog::ACTION_SYNC_FAILED,
            $integration,
            ['capability' => $capability, 'error' => mb_substr($error->getMessage(), 0, 500)],
        );

        $status = $this->statusCodeOf($error);

        // A 401/403 from the provider means the grant itself is gone; waiting
        // for a second failure would only delay the reauthorization prompt.
        if ($status === 401 || $status === 403) {
            $this->refresher->refresh($integration->fresh() ?? $integration);
        }

        if ($failures === self::NOTIFY_AFTER_FAILURES || $status === 401 || $status === 403) {
            $integration->user?->notify(new IntegrationSyncFailed($integration->fresh() ?? $integration, $capability, $message));
        }

        return ['ok' => false, 'summary' => $message];
    }

    /**
     * The HTTP status behind a provider error, when there is one.
     */
    protected function statusCodeOf(\Throwable $error): ?int
    {
        if ($error instanceof RequestException && $error->response !== null) {
            return $error->response->status();
        }

        return null;
    }

    /**
     * A provider error phrased for the person who has to act on it.
     */
    protected function friendlyMessage(\Throwable $error): string
    {
        $status = $this->statusCodeOf($error);

        return match (true) {
            $status === 401, $status === 403 => 'Permission revoked in the provider — please reauthorize.',
            $status !== null && $status >= 500 => 'The provider is having trouble. Sync will retry automatically.',
            default => 'Sync failed. Try again, or reconnect the account if this continues.',
        };
    }
}
