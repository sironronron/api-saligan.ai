<?php

use App\Models\Conversation;
use App\Models\Document;
use App\Models\Invitation;
use App\Models\LegalCase;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    // The inline-invite path sends a real invitation notification; nothing here
    // is asserting on the mail itself.
    Notification::fake();

    $this->organization = Organization::factory()->create();

    $this->owner = User::factory()->ownerOf($this->organization)->create();
    $this->colleague = User::factory()->memberOf($this->organization)->create();
    $this->outsider = User::factory()->create();

    $this->plan = Plan::factory()->pro()->create();

    Subscription::factory()->for($this->owner)->create([
        'organization_id' => $this->organization->id,
        'plan_id' => $this->plan->id,
        'seats_purchased' => 10,
    ]);

    // Pinned open: the factory picks a status at random, and a 'closed' roll
    // freezes the roster, which would make every assignment case here flaky.
    $this->case = LegalCase::factory()->for($this->owner)->create([
        'organization_id' => $this->organization->id,
        'status' => 'open',
    ]);
});

/**
 * A solo account carrying its own subscription. Without one the active
 * subscription middleware answers 402 and the assertion under test never runs.
 * Reuses the plan from beforeEach — a second Pro row collides on the slug.
 */
function subscribedUser(Plan $plan): User
{
    $user = User::factory()->create();

    Subscription::factory()->for($user)->create(['plan_id' => $plan->id]);

    return $user;
}

it('assigns a colleague to a case', function () {
    $this->signInAs($this->owner)
        ->postJson("/api/cases/{$this->case->id}/assignees", ['user_id' => $this->colleague->id])
        ->assertCreated()
        ->assertJsonPath('assignees.0.id', $this->colleague->id);

    $this->assertDatabaseHas('case_user', [
        'case_id' => $this->case->id,
        'user_id' => $this->colleague->id,
        'assigned_by' => $this->owner->id,
    ]);
});

it('lets an assignee open a case they do not own', function () {
    $this->case->assignees()->attach($this->colleague->id);

    $this->signInAs($this->colleague)
        ->getJson("/api/cases/{$this->case->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $this->case->id)
        ->assertJsonPath('data.is_owner', false);
});

it('lists assigned cases alongside owned ones', function () {
    $this->case->assignees()->attach($this->colleague->id);

    $own = LegalCase::factory()->for($this->colleague)->create([
        'organization_id' => $this->organization->id,
    ]);

    $ids = $this->signInAs($this->colleague)->getJson('/api/cases')->assertOk()->json('data.*.id');

    expect($ids)->toHaveCount(2)
        ->and($ids)->toContain($this->case->id)
        ->and($ids)->toContain($own->id);
});

it('keeps a case out of the list for someone who is not on it', function () {
    $ids = $this->signInAs($this->colleague)->getJson('/api/cases')->assertOk()->json('data.*.id');

    expect($ids)->toBe([]);
});

it('still refuses a case to an unassigned colleague', function () {
    $this->signInAs($this->colleague)
        ->getJson("/api/cases/{$this->case->id}")
        ->assertForbidden();
});

it('lets an assignee work the case but not dispose of it', function () {
    $this->case->assignees()->attach($this->colleague->id);

    // Working the matter: allowed.
    $this->signInAs($this->colleague)
        ->patchJson("/api/cases/{$this->case->id}/status", ['status' => 'in_progress'])
        ->assertOk();

    // Archiving it: the owner's call.
    $this->signInAs($this->colleague)
        ->deleteJson("/api/cases/{$this->case->id}")
        ->assertForbidden();
});

it('lets an assignee message a thread the owner started', function () {
    $this->case->assignees()->attach($this->colleague->id);

    $conversation = Conversation::factory()->for($this->owner)->create([
        'case_id' => $this->case->id,
    ]);

    $this->signInAs($this->colleague)
        ->getJson("/api/conversations/{$conversation->id}")
        ->assertOk();
});

it('does not leak an unrelated thread through case access', function () {
    $this->case->assignees()->attach($this->colleague->id);

    // No case attached, so it stays private to the owner.
    $private = Conversation::factory()->for($this->owner)->create(['case_id' => null]);

    $this->signInAs($this->colleague)
        ->getJson("/api/conversations/{$private->id}")
        ->assertForbidden();
});

it('shows an assignee the documents attached to the case', function () {
    $this->case->assignees()->attach($this->colleague->id);

    $onCase = Document::factory()->for($this->owner)->create(['case_id' => $this->case->id]);
    $private = Document::factory()->for($this->owner)->create(['case_id' => null]);

    $ids = $this->signInAs($this->colleague)
        ->getJson("/api/documents?case_id={$this->case->id}")
        ->assertOk()
        ->json('data.*.id');

    expect($ids)->toContain($onCase->id)
        ->and($ids)->not->toContain($private->id);
});

it('refuses to assign someone from another organization', function () {
    $this->signInAs($this->owner)
        ->postJson("/api/cases/{$this->case->id}/assignees", ['user_id' => $this->outsider->id])
        ->assertUnprocessable();
});

it('refuses to assign the owner to their own case', function () {
    $this->signInAs($this->owner)
        ->postJson("/api/cases/{$this->case->id}/assignees", ['user_id' => $this->owner->id])
        ->assertUnprocessable();
});

it('does not let an assignee change who else is on the case', function () {
    $this->case->assignees()->attach($this->colleague->id);

    $third = User::factory()->memberOf($this->organization)->create();

    $this->signInAs($this->colleague)
        ->postJson("/api/cases/{$this->case->id}/assignees", ['user_id' => $third->id])
        ->assertForbidden();
});

it('lets an assignee take themselves off the case', function () {
    $this->case->assignees()->attach($this->colleague->id);

    $this->signInAs($this->colleague)
        ->deleteJson("/api/cases/{$this->case->id}/assignees/{$this->colleague->id}")
        ->assertOk();

    $this->assertDatabaseMissing('case_user', [
        'case_id' => $this->case->id,
        'user_id' => $this->colleague->id,
    ]);
});

/**
 * A finished matter keeps its roster as the record of who worked it. Every
 * door into changing that roster has to be shut, including the self-removal
 * path that deliberately skips the manageAssignees policy.
 */
it('freezes the roster once the case is closed or archived', function (array $finished) {
    $this->case->forceFill($finished)->save();
    $this->case->assignees()->attach($this->colleague->id);

    $unassigned = User::factory()->memberOf($this->organization)->create();

    $this->signInAs($this->owner)
        ->postJson("/api/cases/{$this->case->id}/assignees", ['user_id' => $unassigned->id])
        ->assertForbidden();

    $this->signInAs($this->owner)
        ->getJson("/api/cases/{$this->case->id}/assignees/candidates")
        ->assertForbidden();

    $this->signInAs($this->owner)
        ->deleteJson("/api/cases/{$this->case->id}/assignees/{$this->colleague->id}")
        ->assertForbidden();

    // Leaving of your own accord is frozen too, and answers 422 rather than
    // 403 because reading the case is still permitted.
    $this->signInAs($this->colleague)
        ->deleteJson("/api/cases/{$this->case->id}/assignees/{$this->colleague->id}")
        ->assertUnprocessable();

    $this->assertDatabaseHas('case_user', [
        'case_id' => $this->case->id,
        'user_id' => $this->colleague->id,
    ]);
})->with([
    'closed' => [['status' => 'closed']],
    'archived' => [['archived_at' => '2026-01-01 00:00:00']],
]);

it('still shows the roster of a closed case, flagged as unmanageable', function () {
    $this->case->forceFill(['status' => 'closed'])->save();

    $this->signInAs($this->owner)
        ->getJson("/api/cases/{$this->case->id}/assignees")
        ->assertOk()
        ->assertJsonPath('can_manage', false);
});

it('never removes the owner from their own case', function () {
    $this->signInAs($this->owner)
        ->deleteJson("/api/cases/{$this->case->id}/assignees/{$this->owner->id}")
        ->assertUnprocessable();
});

it('assigning twice is a no-op rather than a duplicate row', function () {
    foreach (range(1, 2) as $ignored) {
        $this->signInAs($this->owner)
            ->postJson("/api/cases/{$this->case->id}/assignees", ['user_id' => $this->colleague->id])
            ->assertCreated();
    }

    expect($this->case->assignees()->count())->toBe(1);
});

it('offers only unassigned active colleagues as candidates', function () {
    $this->case->assignees()->attach($this->colleague->id);

    $available = User::factory()->memberOf($this->organization)->create();
    User::factory()->memberOf($this->organization, User::ORG_ROLE_MEMBER, User::ORG_STATUS_SUSPENDED)->create();

    $ids = $this->signInAs($this->owner)
        ->getJson("/api/cases/{$this->case->id}/assignees/candidates")
        ->assertOk()
        ->json('data.*.id');

    expect($ids)->toBe([$available->id]);
});

it('invites an unknown email and assigns them on acceptance', function () {
    $this->signInAs($this->owner)
        ->postJson("/api/cases/{$this->case->id}/assignees", ['email' => 'new@firm.test'])
        ->assertCreated();

    $invitation = Invitation::where('email', 'new@firm.test')->firstOrFail();
    expect($invitation->case_id)->toBe($this->case->id);

    $joiner = User::factory()->create(['email' => 'new@firm.test']);

    $this->signInAs($joiner)
        ->postJson('/api/organizations/invitations/accept', ['token' => $invitation->token])
        ->assertOk();

    $this->assertDatabaseHas('case_user', [
        'case_id' => $this->case->id,
        'user_id' => $joiner->id,
    ]);
});

it('treats an email that is already a colleague as a plain assignment', function () {
    $this->signInAs($this->owner)
        ->postJson("/api/cases/{$this->case->id}/assignees", ['email' => $this->colleague->email])
        ->assertCreated();

    $this->assertDatabaseHas('case_user', [
        'case_id' => $this->case->id,
        'user_id' => $this->colleague->id,
    ]);

    $this->assertDatabaseMissing('invitations', ['email' => $this->colleague->email]);
});

it('refuses assignment on a case with no organization', function () {
    $solo = subscribedUser($this->plan);
    $soloCase = LegalCase::factory()->for($solo)->create(['organization_id' => null, 'status' => 'open']);
    $other = User::factory()->create();

    $this->signInAs($solo)
        ->postJson("/api/cases/{$soloCase->id}/assignees", ['user_id' => $other->id])
        ->assertUnprocessable();
});

it('does not let one solo account manage another solo account\'s case', function () {
    $solo = User::factory()->create();
    $soloCase = LegalCase::factory()->for($solo)->create(['organization_id' => null, 'status' => 'open']);

    // Both carry a null organization_id; that must never read as "colleagues".
    $this->signInAs(subscribedUser($this->plan))
        ->getJson("/api/cases/{$soloCase->id}/assignees")
        ->assertForbidden();
});

/**
 * The filter narrows what the viewer can already see — it never widens the
 * list to a colleague's private matters — so both cases here are ones the
 * signed-in colleague can open on their own.
 */
it('filters the case list to one person, owned or assigned', function () {
    $ownedByColleague = LegalCase::factory()->for($this->colleague)->create([
        'organization_id' => $this->organization->id,
    ]);

    $this->case->assignees()->attach($this->colleague->id, ['assigned_by' => $this->owner->id]);

    $byColleague = collect($this->signInAs($this->colleague)
        ->getJson("/api/cases?assignee={$this->colleague->id}")
        ->assertOk()
        ->json('data'))->pluck('id');

    expect($byColleague)->toContain($this->case->id, $ownedByColleague->id);

    $byOwner = collect($this->signInAs($this->colleague)
        ->getJson("/api/cases?assignee={$this->owner->id}")
        ->assertOk()
        ->json('data'))->pluck('id');

    expect($byOwner)->toContain($this->case->id)->not->toContain($ownedByColleague->id);
});

it('reads assignee=me as the signed-in user', function () {
    $colleagueOnly = LegalCase::factory()->for($this->colleague)->create([
        'organization_id' => $this->organization->id,
    ]);

    $ids = collect($this->signInAs($this->owner)
        ->getJson('/api/cases?assignee=me')
        ->assertOk()
        ->json('data'))->pluck('id');

    expect($ids)->toContain($this->case->id)->not->toContain($colleagueOnly->id);
});

it('carries assignees on each row of the case list', function () {
    $this->case->assignees()->attach($this->colleague->id, ['assigned_by' => $this->owner->id]);

    $this->signInAs($this->owner)
        ->getJson('/api/cases')
        ->assertOk()
        ->assertJsonPath('data.0.owner.id', $this->owner->id)
        ->assertJsonPath('data.0.assignees.0.id', $this->colleague->id);
});
