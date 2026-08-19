<?php

namespace App\Services\Integrations;

use App\Enums\IntegrationProvider;
use App\Models\Integration;
use App\Models\IntegrationAuditLog;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Writes the audit trail every connect, disconnect, toggle, and policy change
 * leaves behind. An audit row carries everything it needs to stand alone —
 * who, what, which provider, which scopes — because the connection it
 * describes may be gone by the time anyone reads it.
 */
class IntegrationAuditLogger
{
    /**
     * Record one auditable action.
     *
     * @param  array<string, mixed>  $details
     */
    public function log(
        ?User $actor,
        ?IntegrationProvider $provider,
        string $action,
        ?Integration $integration = null,
        array $details = [],
        ?string $ipAddress = null,
    ): IntegrationAuditLog {
        return IntegrationAuditLog::create([
            'user_id' => $actor?->id,
            'organization_id' => $integration?->organization_id ?? $actor?->organization_id,
            'integration_id' => $integration?->id,
            'provider' => $provider,
            'action' => $action,
            'details' => $details,
            'ip_address' => $ipAddress ?? request()?->ip(),
        ]);
    }

    /**
     * Record an action taken from an authenticated request.
     *
     * @param  array<string, mixed>  $details
     */
    public function logFromRequest(
        Request $request,
        ?IntegrationProvider $provider,
        string $action,
        ?Integration $integration = null,
        array $details = [],
    ): IntegrationAuditLog {
        return $this->log($request->user(), $provider, $action, $integration, $details, $request->ip());
    }
}
