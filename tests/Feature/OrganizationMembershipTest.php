<?php

use App\Models\Invitation;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\OrganizationInvite;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->organization = Organization::factory()->create(['name' => 'Acme Law Office']);
    $this->owner = User::factory()->ownerOf($this->organization)->create(['email' => 'owner@acme.test']);
    $this->subscription = Subscription::factory()->for($this->organization)->for($this->owner)->create([
        'plan_id' => Plan::factory()->pro()->create()->id,
        'seats_purchased' => 3,
        'price_per_seat' => 200000,
    ]);
});

it('creates an organization for an already authenticated user', function () {
    $user = User::factory()->create(['email' => 'solo@example.com']);

    $this->signInAs($user)
        ->postJson('/api/organizations', ['name' => 'Solo Practice'])
        ->assertCreated()
        ->assertJsonPath('data.organization_id', fn ($id) => $id !== null)
        ->assertJsonPath('data.org_role', User::ORG_ROLE_OWNER)
        ->assertJsonPath('data.org_status', User::ORG_STATUS_ACTIVE);

    $this->assertDatabaseHas('organizations', ['name' => 'Solo Practice']);
    expect($user->fresh()->organization_id)->not->toBeNull();
});

it('rejects creating an organization when the user already belongs to one', function () {
    $this->signInAs($this->owner)
        ->postJson('/api/organizations', ['name' => 'Second Org'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'You already belong to an organization.');
});

it('requires a name when creating an organization', function () {
    $user = User::factory()->create();

    $this->signInAs($user)
        ->postJson('/api/organizations', ['name' => ''])
        ->assertUnprocessable();
});

it('joins an organization by accepting an emailed invite', function () {
    // Accounts are created in Supabase, so an invited user arrives already
    // authenticated and redeems the token instead of registering with it.
    $invitation = Invitation::factory()->for($this->organization)->create([
        'invited_by' => $this->owner->id,
        'email' => 'newbie@example.com',
    ]);

    $newbie = User::factory()->create(['email' => 'newbie@example.com']);

    $this->signInAs($newbie)
        ->postJson('/api/organizations/invitations/accept', ['token' => $invitation->token])
        ->assertOk()
        ->assertJsonPath('data.organization_id', $this->organization->id)
        ->assertJsonPath('data.org_role', 'member')
        ->assertJsonPath('data.org_status', 'active');

    expect($invitation->fresh()->status)->toBe(Invitation::STATUS_ACCEPTED);
});

it('rejects accepting an invite when the user already belongs to an organization', function () {
    $invitation = Invitation::factory()->for($this->organization)->create([
        'invited_by' => $this->owner->id,
        'email' => 'taken@example.com',
    ]);

    $other = Organization::factory()->create(['name' => 'Other Firm']);
    $user = User::factory()->memberOf($other)->create(['email' => 'taken@example.com']);

    $this->signInAs($user)
        ->postJson('/api/organizations/invitations/accept', ['token' => $invitation->token])
        ->assertUnprocessable();

    expect($user->fresh()->organization_id)->toBe($other->id)
        ->and($invitation->fresh()->status)->toBe(Invitation::STATUS_PENDING);
});

it('rejects accepting an invite sent to a different email', function () {
    $invitation = Invitation::factory()->for($this->organization)->create([
        'invited_by' => $this->owner->id,
        'email' => 'invited@example.com',
    ]);

    $stranger = User::factory()->create(['email' => 'someone-else@example.com']);

    $this->signInAs($stranger)
        ->postJson('/api/organizations/invitations/accept', ['token' => $invitation->token])
        ->assertUnprocessable();

    expect($stranger->fresh()->organization_id)->toBeNull();
});

it('rejects an invalid invite token', function () {
    $user = User::factory()->create(['email' => 'jane@example.com']);

    $this->signInAs($user)
        ->postJson('/api/organizations/invitations/accept', ['token' => 'does-not-exist'])
        ->assertStatus(422);
});

it('shows the organization, its seats, and its members', function () {
    $member = User::factory()->memberOf($this->organization)->create();

    $this->signInAs($this->owner)->getJson('/api/organizations')
        ->assertOk()
        ->assertJsonPath('data.name', 'Acme Law Office')
        ->assertJsonPath('data.role', 'owner')
        ->assertJsonPath('data.seats.purchased', 3)
        ->assertJsonPath('data.seats.used', 2)
        ->assertJsonPath('data.seats.pending_invites', 0)
        ->assertJsonPath('data.seats.free', 1)
        ->assertJsonCount(2, 'data.members')
        ->assertJsonFragment(['email' => $member->email]);
});

it('lists members for any active member', function () {
    $member = User::factory()->memberOf($this->organization)->create();

    $this->signInAs($member)->getJson('/api/organizations/members')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('lets an admin invite a new member when a seat is free', function () {
    Notification::fake();

    $this->signInAs($this->owner)
        ->postJson('/api/organizations/invitations', ['email' => 'lawyer@example.com'])
        ->assertCreated()
        ->assertJsonPath('data.email', 'lawyer@example.com')
        ->assertJsonPath('data.status', 'pending');

    $this->assertDatabaseHas('invitations', [
        'organization_id' => $this->organization->id,
        'email' => 'lawyer@example.com',
        'status' => Invitation::STATUS_PENDING,
    ]);

    Notification::assertSentTo(Invitation::first(), OrganizationInvite::class);
});

it('blocks non-admin members from inviting', function () {
    $member = User::factory()->memberOf($this->organization)->create();

    $this->signInAs($member)
        ->postJson('/api/organizations/invitations', ['email' => 'lawyer@example.com'])
        ->assertStatus(403);
});

it('blocks inviting an email already tied to another organization', function () {
    $otherOrg = Organization::factory()->create();
    User::factory()->memberOf($otherOrg)->create(['email' => 'taken@example.com']);

    $this->signInAs($this->owner)
        ->postJson('/api/organizations/invitations', ['email' => 'taken@example.com'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'This email already belongs to another organization.');
});

it('blocks duplicate pending invites for the same email and organization', function () {
    $invitation = Invitation::factory()->for($this->organization)->create([
        'invited_by' => $this->owner->id,
        'email' => 'lawyer@example.com',
    ]);

    $this->signInAs($this->owner)
        ->postJson('/api/organizations/invitations', ['email' => 'lawyer@example.com'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'An invitation to this email is already pending.');

    expect($invitation->fresh()->token)->toBe($invitation->token);
});

it('blocks inviting when no seat is free', function () {
    User::factory()->memberOf($this->organization)->create();
    User::factory()->memberOf($this->organization)->create();
    Invitation::factory()->for($this->organization)->create([
        'invited_by' => $this->owner->id,
        'email' => 'pending@example.com',
    ]);

    // 3 purchased, 2 active + 1 pending already.
    $this->signInAs($this->owner)
        ->postJson('/api/organizations/invitations', ['email' => 'lawyer@example.com'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'No free seats remain on your plan. Add seats or remove a member before inviting.');
});

it('lets the invited user accept the invitation', function () {
    $invitation = Invitation::factory()->for($this->organization)->create([
        'invited_by' => $this->owner->id,
        'email' => 'lawyer@example.com',
    ]);

    $user = User::factory()->create(['email' => 'lawyer@example.com']);

    $this->signInAs($user)
        ->postJson("/api/invitations/{$invitation->id}/accept")
        ->assertOk()
        ->assertJsonPath('data.organization_id', $this->organization->id)
        ->assertJsonPath('data.org_role', 'member');

    expect($invitation->fresh()->status)->toBe(Invitation::STATUS_ACCEPTED)
        ->and($user->fresh()->org_status)->toBe(User::ORG_STATUS_ACTIVE);
});

it('rejects accepting an invitation sent to a different email', function () {
    $invitation = Invitation::factory()->for($this->organization)->create([
        'invited_by' => $this->owner->id,
        'email' => 'lawyer@example.com',
    ]);

    $user = User::factory()->create(['email' => 'someone-else@example.com']);

    $this->signInAs($user)
        ->postJson("/api/invitations/{$invitation->id}/accept")
        ->assertStatus(422)
        ->assertJsonPath('message', 'This invitation was not sent to your email address.');
});

it('rejects accepting an expired invitation', function () {
    $invitation = Invitation::factory()->for($this->organization)->expired()->create([
        'invited_by' => $this->owner->id,
        'email' => 'lawyer@example.com',
    ]);

    $user = User::factory()->create(['email' => 'lawyer@example.com']);

    $this->signInAs($user)
        ->postJson("/api/invitations/{$invitation->id}/accept")
        ->assertStatus(422)
        ->assertJsonPath('message', 'This invitation is no longer valid. Ask an admin to send a new one.');
});

it('rejects accepting an invitation when the user already belongs to an organization', function () {
    $invitation = Invitation::factory()->for($this->organization)->create([
        'invited_by' => $this->owner->id,
        'email' => 'lawyer@example.com',
    ]);

    $otherOrg = Organization::factory()->create();
    $user = User::factory()->memberOf($otherOrg)->create(['email' => 'lawyer@example.com']);

    $this->signInAs($user)
        ->postJson("/api/invitations/{$invitation->id}/accept")
        ->assertStatus(422)
        ->assertJsonPath('message', 'You already belong to an organization.');
});

it('lets an admin revoke a pending invitation and frees the seat', function () {
    $invitation = Invitation::factory()->for($this->organization)->create([
        'invited_by' => $this->owner->id,
        'email' => 'lawyer@example.com',
    ]);

    $this->signInAs($this->owner)
        ->deleteJson("/api/organizations/invitations/{$invitation->id}")
        ->assertNoContent();

    expect($invitation->fresh()->status)->toBe(Invitation::STATUS_REVOKED)
        ->and($this->organization->pendingInvitesCount())->toBe(0)
        ->and($this->organization->freeSeats())->toBe(2);
});

it('blocks revoking another organizations invitation', function () {
    $otherOrg = Organization::factory()->create();
    $otherOwner = User::factory()->ownerOf($otherOrg)->create();
    $invitation = Invitation::factory()->for($otherOrg)->create([
        'invited_by' => $otherOwner->id,
        'email' => 'lawyer@example.com',
    ]);

    $this->signInAs($this->owner)
        ->deleteJson("/api/organizations/invitations/{$invitation->id}")
        ->assertStatus(403);
});

it('lets an admin remove a member and frees their seat immediately', function () {
    $member = User::factory()->memberOf($this->organization)->create();

    $this->signInAs($this->owner)
        ->deleteJson("/api/organizations/members/{$member->id}")
        ->assertNoContent();

    expect($member->fresh()->organization_id)->toBeNull()
        ->and($this->organization->seatsUsed())->toBe(1)
        ->and($this->organization->freeSeats())->toBe(2);
});

it('blocks removing the organization owner', function () {
    $this->signInAs($this->owner)
        ->deleteJson("/api/organizations/members/{$this->owner->id}")
        ->assertStatus(422);
});

it('blocks non-admins from removing members', function () {
    $member = User::factory()->memberOf($this->organization)->create();
    $otherMember = User::factory()->memberOf($this->organization)->create();

    $this->signInAs($otherMember)
        ->deleteJson("/api/organizations/members/{$member->id}")
        ->assertStatus(403);
});

it('lets an admin suspend and resume a member', function () {
    $member = User::factory()->memberOf($this->organization)->create();

    $this->signInAs($this->owner)
        ->postJson("/api/organizations/members/{$member->id}/suspend")
        ->assertOk()
        ->assertJsonPath('data.org_status', User::ORG_STATUS_SUSPENDED);

    $this->signInAs($this->owner)
        ->postJson("/api/organizations/members/{$member->id}/resume")
        ->assertOk()
        ->assertJsonPath('data.org_status', User::ORG_STATUS_ACTIVE);
});

it('requires an active subscription before inviting', function () {
    $this->subscription->delete();

    $this->signInAs($this->owner)
        ->postJson('/api/organizations/invitations', ['email' => 'lawyer@example.com'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Your organization needs an active subscription before you can invite members.');
});
