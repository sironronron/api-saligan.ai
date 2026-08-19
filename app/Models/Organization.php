<?php

namespace App\Models;

use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\URL;

#[Fillable([
    'name',
    'description',
    'website',
    'integrations_connection_mode',
    'integration_capability_policies',
])]
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    /** Each member connects their own add-on account. */
    public const INTEGRATIONS_MODE_PER_SEAT = 'per_seat';

    /** An admin connects once on behalf of the whole firm. */
    public const INTEGRATIONS_MODE_FIRM_WIDE = 'firm_wide';

    /** Org policy: the capability is always on for every member. */
    public const CAPABILITY_POLICY_FORCED_ON = 'forced_on';

    /** Org policy: the capability is off for every member. */
    public const CAPABILITY_POLICY_FORCED_OFF = 'forced_off';

    /** How long a logo link stays good for. Long enough to survive a session. */
    public const LOGO_URL_TTL_DAYS = 7;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'integration_capability_policies' => 'array',
        ];
    }

    /**
     * A link the browser can put straight in an `<img src>`.
     *
     * The file lives on the private disk, and the API is bearer-authenticated,
     * so an `<img>` tag cannot reach it the ordinary way. A signed URL carries
     * its own proof instead of needing a header, without exposing the storage
     * path or opening the disk to the public.
     */
    public function logoUrl(): ?string
    {
        if ($this->logo_path === null) {
            return null;
        }

        return URL::temporarySignedRoute(
            'organizations.logo',
            now()->addDays(self::LOGO_URL_TTL_DAYS),
            ['organization' => $this->id],
        );
    }

    /**
     * The users that belong to this organization.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * The invitations sent on behalf of this organization.
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }

    /**
     * The organization's current billing subscription.
     */
    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->latestOfMany();
    }

    /**
     * All billing subscriptions this organization has had.
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * The user who owns this organization (the account that created it).
     */
    public function owner(): ?User
    {
        return $this->users()
            ->where('org_role', User::ORG_ROLE_OWNER)
            ->first();
    }

    /**
     * Whether the given user is a member of this organization.
     */
    public function isMember(User $user): bool
    {
        return $user->organization_id === $this->id;
    }

    /**
     * Whether the given user can manage this organization's members and seats.
     */
    public function canManage(User $user): bool
    {
        return $this->isMember($user) && in_array($user->org_role, [User::ORG_ROLE_OWNER, User::ORG_ROLE_ADMIN], true);
    }

    /**
     * The number of active seats currently used by active members.
     */
    public function seatsUsed(): int
    {
        return $this->users()
            ->where('org_status', User::ORG_STATUS_ACTIVE)
            ->count();
    }

    /**
     * The number of pending (not yet accepted, not revoked, not expired)
     * invitations that still count against the seat pool.
     */
    public function pendingInvitesCount(): int
    {
        return $this->invitations()->active()->count();
    }

    /**
     * The number of seats that are free to send an invite for, or null when
     * the organization has no subscription.
     */
    public function freeSeats(): ?int
    {
        $purchased = $this->subscription?->seats_purchased;

        if ($purchased === null) {
            return null;
        }

        return max(0, $purchased - $this->seatsUsed() - $this->pendingInvitesCount());
    }

    /**
     * Whether a seat is available for a new invite or accepted member.
     */
    public function hasFreeSeat(): bool
    {
        return $this->freeSeats() > 0;
    }

    /**
     * The firm-wide add-on connections an admin made on the organization's
     * behalf, keyed by provider value.
     *
     * @return HasMany<Integration, $this>
     */
    public function integrations(): HasMany
    {
        return $this->hasMany(Integration::class);
    }

    /**
     * Whether add-on connections are made once for the whole firm instead of
     * per seat.
     */
    public function usesFirmWideIntegrations(): bool
    {
        return $this->integrations_connection_mode === self::INTEGRATIONS_MODE_FIRM_WIDE;
    }

    /**
     * The org-wide policy for a capability, or null when each member chooses.
     */
    public function capabilityPolicy(string $capability): ?string
    {
        $policies = $this->integration_capability_policies ?? [];

        return $policies[$capability] ?? null;
    }

    /**
     * Whether the org forces a capability on for every member.
     */
    public function capabilityForcedOn(string $capability): bool
    {
        return $this->capabilityPolicy($capability) === self::CAPABILITY_POLICY_FORCED_ON;
    }

    /**
     * Whether the org blocks a capability for every member.
     */
    public function capabilityForcedOff(string $capability): bool
    {
        return $this->capabilityPolicy($capability) === self::CAPABILITY_POLICY_FORCED_OFF;
    }
}
