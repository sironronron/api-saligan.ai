<?php

namespace App\Services\Integrations;

use App\Enums\IntegrationProvider;
use App\Models\Integration;
use App\Models\IntegrationAuditLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The firm-side controls for add-ons: choosing whether connections are per
 * seat or firm-wide, enforcing capabilities org-wide, and reading back who
 * has connected what.
 *
 * Everything here assumes the caller has already been checked as an
 * organization manager; the controller does that before any of this runs.
 */
class IntegrationAdminService
{
    public function __construct(
        protected readonly IntegrationAuditLogger $audit,
    ) {
        //
    }

    /**
     * Set whether members connect their own accounts or an admin connects
     * once for the whole firm.
     */
    public function setConnectionMode(User $actor, Organization $organization, string $mode): Organization
    {
        abort_unless(
            in_array($mode, [Organization::INTEGRATIONS_MODE_PER_SEAT, Organization::INTEGRATIONS_MODE_FIRM_WIDE], true),
            422,
            'Unknown connection mode.',
        );

        $previous = $organization->integrations_connection_mode ?? Organization::INTEGRATIONS_MODE_PER_SEAT;

        $organization->forceFill(['integrations_connection_mode' => $mode])->save();

        if ($previous !== $mode) {
            $this->audit->logFromRequest(
                request(),
                IntegrationProvider::GoogleWorkspace,
                IntegrationAuditLog::ACTION_CONNECTION_MODE_CHANGED,
                null,
                ['from' => $previous, 'to' => $mode],
            );
        }

        return $organization;
    }

    /**
     * Apply org-wide capability policies. Each entry is a capability key
     * mapped to forced_on, forced_off, or null to hand the choice back to
     * members.
     *
     * @param  array<string, string|null>  $policies
     */
    public function setCapabilityPolicies(User $actor, Organization $organization, array $policies): Organization
    {
        $allowed = [Organization::CAPABILITY_POLICY_FORCED_ON, Organization::CAPABILITY_POLICY_FORCED_OFF];

        $cleaned = [];

        foreach ($policies as $capability => $policy) {
            if ($policy === null || $policy === '') {
                continue;
            }

            abort_unless(in_array($policy, $allowed, true), 422, "Unknown policy for {$capability}.");

            $cleaned[$capability] = $policy;
        }

        $previous = $organization->integration_capability_policies ?? [];

        $organization->forceFill([
            'integration_capability_policies' => $cleaned === [] ? null : $cleaned,
        ])->save();

        if ($previous !== $cleaned) {
            $this->audit->logFromRequest(
                request(),
                IntegrationProvider::GoogleWorkspace,
                IntegrationAuditLog::ACTION_POLICY_UPDATED,
                null,
                ['policies' => $cleaned],
            );
        }

        return $organization;
    }

    /**
     * Which members have connected which integrations, and which capabilities
     * each connection has on. Shaped for the firm management view.
     *
     * @return array{members: list<array<string, mixed>>, firm_wide: list<array<string, mixed>>}
     */
    public function memberConnections(Organization $organization): array
    {
        $members = $organization->users()
            ->where('org_status', User::ORG_STATUS_ACTIVE)
            ->get();

        $rows = [];

        foreach ($members as $member) {
            $connections = [];

            foreach ($member->integrations as $integration) {
                $connections[] = [
                    'id' => $integration->id,
                    'provider' => $integration->provider->value,
                    'provider_label' => $integration->provider->label(),
                    'status' => $integration->status,
                    'connection_scope' => $integration->connection_scope,
                    'account_email' => $integration->account_email,
                    'connected_at' => $integration->connected_at?->toIso8601String(),
                    'enabled_capabilities' => $integration->enabledCapabilities(),
                ];
            }

            $rows[] = [
                'user_id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'org_role' => $member->org_role,
                'connections' => $connections,
            ];
        }

        // Firm-wide connections belong to the org, not a seat; surface them
        // once under the org itself so the admin sees them even if no member
        // row carries them.
        $firmWide = $organization->integrations()
            ->where('connection_scope', Integration::SCOPE_FIRM_WIDE)
            ->get()
            ->map(fn (Integration $integration): array => [
                'id' => $integration->id,
                'provider' => $integration->provider->value,
                'provider_label' => $integration->provider->label(),
                'status' => $integration->status,
                'connection_scope' => $integration->connection_scope,
                'account_email' => $integration->account_email,
                'connected_at' => $integration->connected_at?->toIso8601String(),
                'enabled_capabilities' => $integration->enabledCapabilities(),
            ])
            ->all();

        return [
            'members' => $rows,
            'firm_wide' => $firmWide,
        ];
    }

    /**
     * The audit trail for the organization, newest first.
     *
     * @return LengthAwarePaginator<IntegrationAuditLog>
     */
    public function auditLogs(Organization $organization, int $perPage = 50)
    {
        return IntegrationAuditLog::query()
            ->where('organization_id', $organization->id)
            ->latest('created_at')
            ->paginate($perPage);
    }
}
