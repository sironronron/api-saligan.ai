<?php

use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Template;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * The other half of PlanFeaturesTest: that class proves the gate refuses, this
 * one proves the gates are actually wired into the endpoints that cost money.
 * A capability sold on the pricing page and checked nowhere is the exact defect
 * this rework exists to remove, so each row of the ladder gets a request that
 * must be refused and one that must not.
 */

/** Sign the user in on a plan carrying exactly these features. */
function onPlanWith(array $features, array $limits = []): User
{
    $user = User::factory()->create();

    $plan = Plan::factory()->create([
        'features' => $features,
        'limits' => $limits + ['active_cases' => null, 'documents_uploaded' => 100, 'messages_used' => 100],
    ]);

    Subscription::factory()->for($user)->create(['plan_id' => $plan->id]);

    return $user;
}

it('refuses a Word export without the exports feature', function () {
    $user = onPlanWith(['drafting']);
    $message = messageFor($user);

    $this->signInAs($user)
        ->post("/api/messages/{$message->id}/export/word")
        ->assertStatus(402)
        ->assertJsonPath('upgrade_required', true);
});

it('refuses a PDF export without the exports feature', function () {
    $user = onPlanWith(['drafting']);
    $message = messageFor($user);

    $this->signInAs($user)
        ->post("/api/messages/{$message->id}/export/pdf")
        ->assertStatus(402);
});

it('allows an export on a plan that carries it', function () {
    $user = onPlanWith(['exports']);
    $message = messageFor($user);

    $this->signInAs($user)
        ->post("/api/messages/{$message->id}/export/word")
        ->assertOk();
});

it('refuses saving a template without the drafting feature', function () {
    $user = onPlanWith(['exports']);

    $this->signInAs($user)
        ->postJson('/api/templates', ['name' => 'Demand letter', 'content' => 'Dear [Name],'])
        ->assertStatus(402)
        ->assertJsonPath('upgrade_required', true);
});

it('allows saving a template on a plan that carries drafting', function () {
    $user = onPlanWith(['drafting']);

    $this->signInAs($user)
        ->postJson('/api/templates', ['name' => 'Demand letter', 'content' => 'Dear [Name],'])
        ->assertCreated();
});

it('still lets any plan read the template library', function () {
    // Reading is deliberately ungated: it is drafting from a template that
    // costs money, not looking at one.
    $user = onPlanWith([]);

    $this->signInAs($user)->getJson('/api/templates')->assertOk();
});

it('refuses creating an organization without the teams feature', function () {
    $user = onPlanWith(['drafting', 'exports']);

    $this->signInAs($user)
        ->postJson('/api/organizations', ['name' => 'Solo Practice'])
        ->assertStatus(402)
        ->assertJsonPath('upgrade_required', true);
});

it('allows creating an organization on a team plan', function () {
    $user = onPlanWith(['teams']);

    $this->signInAs($user)
        ->postJson('/api/organizations', ['name' => 'Acme Law'])
        ->assertCreated();
});

it('refuses an image upload without document intelligence', function () {
    Queue::fake();
    Storage::fake('local');

    $user = onPlanWith(['drafting']);

    // Refused at upload rather than left to fail in the queue, so the upload
    // allowance is not spent on a document that could never be read.
    $this->signInAs($user)
        ->postJson('/api/documents', ['file' => UploadedFile::fake()->image('scan.png')])
        ->assertStatus(402)
        ->assertJsonPath('upgrade_required', true);
});

it('accepts a text upload on the same plan', function () {
    Queue::fake();
    Storage::fake('local');

    $user = onPlanWith(['drafting']);

    // The feature buys scan reading and auto-filing, never the ability to
    // upload at all.
    $this->signInAs($user)
        ->postJson('/api/documents', [
            'file' => UploadedFile::fake()->createWithContent('memo.txt', 'A note.'),
        ])
        ->assertCreated();
});

it('accepts an image upload on a plan that reads scans', function () {
    Queue::fake();
    Storage::fake('local');

    $user = onPlanWith(['document_intelligence']);

    $this->signInAs($user)
        ->postJson('/api/documents', ['file' => UploadedFile::fake()->image('scan.png')])
        ->assertCreated();
});

/*
 * The downgrade case: the organization outlives the plan that could hold it, so
 * its owner is still an admin of a real organization and still reaches the seat
 * endpoint. Buying another seat has to be refused on the plan, not on the role.
 */
it('refuses buying seats once the plan no longer carries teams', function () {
    $organization = Organization::factory()->create();
    $owner = User::factory()->ownerOf($organization)->create();

    Subscription::factory()->for($organization)->for($owner)->create([
        'plan_id' => Plan::factory()->create(['features' => ['drafting', 'exports']])->id,
    ]);

    $this->signInAs($owner)
        ->postJson('/api/subscription/seats', ['quantity' => 1])
        ->assertStatus(402)
        ->assertJsonPath('upgrade_required', true);
});

/*
 * And the case a team plan without a seat price would hit: nothing to charge,
 * so nothing to sell — said plainly rather than by silently adding a free seat.
 */
it('refuses buying seats on a team plan that sells none', function () {
    $organization = Organization::factory()->create();
    $owner = User::factory()->ownerOf($organization)->create();

    Subscription::factory()->for($organization)->for($owner)->create([
        'plan_id' => Plan::factory()->create(['features' => ['teams'], 'seat_price' => null])->id,
    ]);

    $this->signInAs($owner)
        ->postJson('/api/subscription/seats', ['quantity' => 1])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Your plan does not sell additional seats. Talk to us about a Business plan sized to your team.');
});

/** An assistant message the given user owns, ready to export. */
function messageFor(User $user): Message
{
    $conversation = Conversation::factory()->for($user)->create(['title' => 'Demand letter']);

    return Message::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => MessageRole::Assistant,
        'content' => 'A drafted reply.',
    ]);
}

it('never refuses an admin', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    // Admins bypass the allowance checks already; a capability check that
    // stopped them would be a confusing half-bypass.
    $this->signInAs($admin)
        ->postJson('/api/templates', ['name' => 'Internal', 'content' => 'Body'])
        ->assertCreated();

    expect(Template::where('name', 'Internal')->exists())->toBeTrue();
});
