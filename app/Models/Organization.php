<?php

namespace App\Models;

use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'name',
])]
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

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
}
