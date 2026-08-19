<?php

namespace App\Services\Integrations;

use App\Models\Integration;
use App\Models\IntegrationAuditLog;
use App\Models\Organization;
use App\Models\User;
use App\Support\PlanFeatures;
use App\Support\PlanLimits;

/**
 * Plan gating for add-ons, and what happens to connections when a plan
 * changes.
 *
 * Add-ons ride on the `integrations` plan feature: Pro, Firm, and Business
 * carry it, the tiers below do not. The check here is the server-side truth —
 * the locked cards on the add-ons page are an upsell, not a gate, and every
 * connect, toggle, and sync runs through this class first.
 */
class IntegrationEligibility
{
    public function __construct(
        protected readonly IntegrationAuditLogger $audit,
    ) {
        //
    }

    /**
     * Whether the user's plan carries the add-ons capability. An inactive
     * subscription carries nothing, so access is part of the question — a
     * cancelled Pro plan is not a Pro plan until it is paid again.
     */
    public function isEligible(User $user): bool
    {
        return PlanLimits::hasActiveAccess($user)
            && PlanFeatures::has($user, PlanFeatures::INTEGRATIONS);
    }

    /**
     * Abort with the standard 402 upgrade response when the plan does not
     * carry add-ons.
     */
    public function ensureEligible(User $user): void
    {
        PlanFeatures::ensureHas($user, PlanFeatures::INTEGRATIONS);
    }

    /**
     * Pause every connection whose user is no longer on an add-on plan, and
     * wake every paused connection whose user is back on one.
     *
     * Pausing keeps the tokens and every setting in place — a downgrade is a
     * pause button, not a delete — but it stops all syncing immediately, so a
     * downgraded account never runs an integration it no longer pays for.
     *
     * @return array{paused: int, resumed: int}
     */
    public function sweep(): array
    {
        $paused = 0;
        $resumed = 0;

        Integration::query()
            ->where('status', Integration::STATUS_CONNECTED)
            ->with('user')
            ->chunkById(100, function ($integrations) use (&$paused): void {
                foreach ($integrations as $integration) {
                    if ($integration->user !== null && ! $this->isEligible($integration->user)) {
                        $this->pause($integration);
                        $paused++;
                    }
                }
            });

        Integration::query()
            ->where('status', Integration::STATUS_PAUSED)
            ->where('paused_reason', Integration::PAUSE_REASON_PLAN_DOWNGRADE)
            ->with('user')
            ->chunkById(100, function ($integrations) use (&$resumed): void {
                foreach ($integrations as $integration) {
                    if ($integration->user !== null && $this->isEligible($integration->user)) {
                        $this->resume($integration);
                        $resumed++;
                    }
                }
            });

        return ['paused' => $paused, 'resumed' => $resumed];
    }

    /**
     * Pause one connection because its plan no longer carries add-ons.
     */
    public function pause(Integration $integration): void
    {
        if ($integration->isPaused()) {
            return;
        }

        $integration->forceFill([
            'status' => Integration::STATUS_PAUSED,
            'paused_at' => now(),
            'paused_reason' => Integration::PAUSE_REASON_PLAN_DOWNGRADE,
        ])->save();

        $this->audit->log(
            $integration->user,
            $integration->provider,
            IntegrationAuditLog::ACTION_PAUSED_PLAN_DOWNGRADE,
            $integration,
        );
    }

    /**
     * Wake a connection paused by a downgrade, now that the plan carries
     * add-ons again. Settings were never touched, so resuming restores
     * exactly what the user had.
     */
    public function resume(Integration $integration): void
    {
        if (! $integration->isPaused()) {
            return;
        }

        $integration->forceFill([
            'status' => Integration::STATUS_CONNECTED,
            'paused_at' => null,
            'paused_reason' => null,
        ])->save();

        $this->audit->log(
            $integration->user,
            $integration->provider,
            IntegrationAuditLog::ACTION_RESUMED_PLAN_UPGRADE,
            $integration,
        );
    }

    /**
     * Apply the eligibility rules to one user right now — called the moment a
     * subscription changes rather than waiting for the next sweep.
     *
     * @return array{paused: int, resumed: int}
     */
    public function syncUser(User $user): array
    {
        $paused = 0;
        $resumed = 0;

        foreach ($user->integrations as $integration) {
            if ($this->isEligible($user) && $integration->isPaused()) {
                $this->resume($integration);
                $resumed++;
            }

            if (! $this->isEligible($user) && $integration->isConnected()) {
                $this->pause($integration);
                $paused++;
            }
        }

        return ['paused' => $paused, 'resumed' => $resumed];
    }

    /**
     * Reconcile every integration that hangs off an organization's plan: each
     * member's personal connections and any firm-wide one. A subscription
     * belongs to the organization, so a change to it touches every seat.
     *
     * @return array{paused: int, resumed: int}
     */
    public function syncOrganization(Organization $organization): array
    {
        $paused = 0;
        $resumed = 0;

        foreach ($organization->users as $member) {
            $result = $this->syncUser($member);
            $paused += $result['paused'];
            $resumed += $result['resumed'];
        }

        foreach ($organization->integrations as $integration) {
            $owner = $integration->user;

            if ($owner === null) {
                continue;
            }

            if ($this->isEligible($owner) && $integration->isPaused()) {
                $this->resume($integration);
                $resumed++;
            }

            if (! $this->isEligible($owner) && $integration->isConnected()) {
                $this->pause($integration);
                $paused++;
            }
        }

        return ['paused' => $paused, 'resumed' => $resumed];
    }
}
