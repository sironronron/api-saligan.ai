<?php

namespace App\Services\Integrations;

use App\Models\Integration;
use App\Models\IntegrationAuditLog;
use App\Notifications\IntegrationReauthorizationRequired;
use Illuminate\Support\Carbon;

/**
 * Keeps stored access tokens alive.
 *
 * A refresh that fails is treated as the provider telling us the grant is
 * gone — an admin revoked the app, the user changed their password, or the
 * consent simply expired. The connection is moved to needs-reauthorization,
 * the user is told, and the failure is audited; nothing keeps silently
 * retrying a token the provider has already rejected.
 */
class TokenRefresher
{
    public function __construct(
        protected readonly Providers\ProviderOAuthClients $clients,
        protected readonly IntegrationAuditLogger $audit,
    ) {
        //
    }

    /**
     * Refresh the connection's access token. Returns false when the provider
     * refused, in which case the connection has already been marked as
     * needing reauthorization.
     */
    public function refresh(Integration $integration): bool
    {
        if ($integration->refresh_token === null) {
            return $this->markNeedsReauthorization($integration, 'No refresh token is stored.');
        }

        try {
            $payload = $this->clients->for($integration->provider)->refreshToken($integration->refresh_token);
        } catch (\Throwable $e) {
            return $this->markNeedsReauthorization($integration, $e->getMessage());
        }

        $integration->forceFill([
            'access_token' => $payload['access_token'],
            // Microsoft rotates the refresh token on every use; Google keeps it
            // stable. Store whichever the answer carries.
            'refresh_token' => $payload['refresh_token'] ?? $integration->refresh_token,
            'token_expires_at' => $payload['expires_in'] !== null
                ? Carbon::now()->addSeconds($payload['expires_in'])
                : null,
            'granted_scopes' => $payload['scope'] !== null
                ? explode(' ', $payload['scope'])
                : $integration->granted_scopes,
            'status' => Integration::STATUS_CONNECTED,
        ])->save();

        return true;
    }

    /**
     * Move the connection to needs-reauthorization, tell the user once, and
     * leave an audit row.
     */
    protected function markNeedsReauthorization(Integration $integration, string $reason): bool
    {
        $alreadyFlagged = $integration->needsReauthorization();

        $integration->forceFill(['status' => Integration::STATUS_NEEDS_REAUTHORIZATION])->save();

        if (! $alreadyFlagged) {
            $this->audit->log(
                $integration->user,
                $integration->provider,
                IntegrationAuditLog::ACTION_TOKEN_REFRESH_FAILED,
                $integration,
                ['reason' => mb_substr($reason, 0, 500)],
            );

            $integration->user?->notify(new IntegrationReauthorizationRequired($integration));
        }

        return false;
    }
}
