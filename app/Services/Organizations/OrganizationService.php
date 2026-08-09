<?php

namespace App\Services\Organizations;

use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\OrganizationInvite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrganizationService
{
    /**
     * Create a new organization and make the given user its owner.
     */
    public function createOrganization(string $name, User $owner): Organization
    {
        return DB::transaction(function () use ($name, $owner): Organization {
            $organization = Organization::create(['name' => trim($name)]);

            $owner->forceFill([
                'organization_id' => $organization->id,
                'org_role' => User::ORG_ROLE_OWNER,
                'org_status' => User::ORG_STATUS_ACTIVE,
            ])->save();

            return $organization;
        });
    }

    /**
     * Invite an email to the organization on behalf of an admin. Requires a
     * free seat, an email not already tied to another organization, and no
     * duplicate pending invite for the same email/org pair.
     */
    public function invite(User $invitedBy, Organization $organization, string $email): Invitation
    {
        abort_unless($organization->canManage($invitedBy), 403, 'Only organization admins can invite members.');

        $email = strtolower(trim($email));

        $existing = $organization->invitations()->where('email', $email)->first();

        if ($existing !== null && $existing->status === Invitation::STATUS_PENDING) {
            abort(422, 'An invitation to this email is already pending.');
        }

        $this->assertEmailNotInAnotherOrganization($organization, $email);
        $this->assertFreeSeatAvailable($organization);
        $this->assertNotAlreadyMember($organization, $email);

        $invitation = $existing ?? new Invitation;

        $invitation->fill([
            'organization_id' => $organization->id,
            'invited_by' => $invitedBy->id,
            'email' => $email,
            'token' => Str::random(64),
            'status' => Invitation::STATUS_PENDING,
            'expires_at' => now()->addDays(Invitation::DEFAULT_EXPIRES_DAYS),
        ])->save();

        $this->markUserAsInvited($email);

        $invitation->notify(new OrganizationInvite($invitation, $organization));

        return $invitation->fresh();
    }

    /**
     * Accept an invitation on behalf of the authenticated user. The user's
     * email must match the invite and a seat must still be available.
     */
    public function acceptInvite(User $user, Invitation $invitation): void
    {
        abort_unless(strcasecmp($user->email, $invitation->email) === 0, 422, 'This invitation was not sent to your email address.');

        abort_unless($invitation->isActive(), 422, 'This invitation is no longer valid. Ask an admin to send a new one.');

        abort_unless($user->organization_id === null, 422, 'You already belong to an organization.');

        $this->assertSeatAvailableForAcceptance($invitation->organization);

        DB::transaction(function () use ($user, $invitation): void {
            $user->forceFill([
                'organization_id' => $invitation->organization_id,
                'org_role' => User::ORG_ROLE_MEMBER,
                'org_status' => User::ORG_STATUS_ACTIVE,
            ])->save();

            $invitation->update(['status' => Invitation::STATUS_ACCEPTED]);
        });
    }

    /**
     * Revoke a pending invitation, immediately freeing the seat it reserved.
     */
    public function revoke(Invitation $invitation): void
    {
        $invitation->update(['status' => Invitation::STATUS_REVOKED]);

        $this->clearInvitedStatusIfPending($invitation->email);
    }

    /**
     * Remove a member from the organization, freeing their seat immediately.
     * The owner cannot be removed.
     */
    public function removeMember(User $actor, Organization $organization, User $member): void
    {
        abort_unless($organization->canManage($actor), 403, 'Only organization admins can remove members.');

        abort_unless($organization->isMember($member), 422, 'This user is not a member of your organization.');

        abort_unless($member->org_role !== User::ORG_ROLE_OWNER, 422, 'The organization owner cannot be removed.');

        $member->forceFill([
            'organization_id' => null,
            'org_role' => null,
            'org_status' => null,
        ])->save();
    }

    /**
     * Suspend a member's access to the organization.
     */
    public function suspendMember(User $actor, Organization $organization, User $member): void
    {
        abort_unless($organization->canManage($actor), 403, 'Only organization admins can suspend members.');

        abort_unless($organization->isMember($member), 422, 'This user is not a member of your organization.');

        abort_unless($member->org_role !== User::ORG_ROLE_OWNER, 422, 'The organization owner cannot be suspended.');

        $member->forceFill(['org_status' => User::ORG_STATUS_SUSPENDED])->save();
    }

    /**
     * Restore a suspended member's access.
     */
    public function resumeMember(User $actor, Organization $organization, User $member): void
    {
        abort_unless($organization->canManage($actor), 403, 'Only organization admins can reactivate members.');

        abort_unless($organization->isMember($member), 422, 'This user is not a member of your organization.');

        $member->forceFill(['org_status' => User::ORG_STATUS_ACTIVE])->save();
    }

    /**
     * The email may not belong to a registered user who is already a member of
     * a different organization.
     */
    protected function assertEmailNotInAnotherOrganization(Organization $organization, string $email): void
    {
        $conflict = User::query()
            ->where('email', $email)
            ->whereNotNull('organization_id')
            ->where('organization_id', '!=', $organization->id)
            ->exists();

        abort_if($conflict, 422, 'This email already belongs to another organization.');
    }

    /**
     * The email may not belong to an active member of this same organization.
     */
    protected function assertNotAlreadyMember(Organization $organization, string $email): void
    {
        $member = $organization->users()
            ->where('email', $email)
            ->where('org_status', User::ORG_STATUS_ACTIVE)
            ->exists();

        abort_if($member, 422, 'This email is already a member of your organization.');
    }

    /**
     * Sending an invite requires seats_used + pending_invites < seats_purchased.
     */
    protected function assertFreeSeatAvailable(Organization $organization): void
    {
        $purchased = $organization->subscription?->seats_purchased;

        abort_if($purchased === null, 422, 'Your organization needs an active subscription before you can invite members.');

        $used = $organization->seatsUsed() + $organization->pendingInvitesCount();

        abort_if($used >= $purchased, 422, 'No free seats remain on your plan. Add seats or remove a member before inviting.');
    }

    /**
     * Accepting an invite still requires the seat it reserved to be free:
     * seats_used + pending_invites must not exceed seats_purchased.
     */
    protected function assertSeatAvailableForAcceptance(Organization $organization): void
    {
        $purchased = $organization->subscription?->seats_purchased;

        abort_if($purchased === null, 422, 'Your organization no longer has an active subscription.');

        $used = $organization->seatsUsed() + $organization->pendingInvitesCount();

        abort_if($used > $purchased, 422, 'No free seats remain on your organization\'s plan.');
    }

    /**
     * When the invited email belongs to a registered user who has not joined
     * any organization, reflect the pending invite in their org_status so the
     * invited state is visible.
     */
    protected function markUserAsInvited(string $email): void
    {
        User::query()
            ->where('email', $email)
            ->whereNull('organization_id')
            ->update(['org_status' => User::ORG_STATUS_INVITED]);
    }

    /**
     * Clear the invited status once the invite is no longer outstanding, but
     * only for users who never joined an organization.
     */
    protected function clearInvitedStatusIfPending(string $email): void
    {
        User::query()
            ->where('email', $email)
            ->whereNull('organization_id')
            ->where('org_status', User::ORG_STATUS_INVITED)
            ->update(['org_status' => null]);
    }
}
