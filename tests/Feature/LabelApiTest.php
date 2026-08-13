<?php

use App\Enums\LabelKind;
use App\Models\Document;
use App\Models\Label;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\LabelSeeder;

beforeEach(function () {
    $this->organization = Organization::factory()->create(['name' => 'Acme Law Office']);
    $this->owner = User::factory()->ownerOf($this->organization)->create();
    $this->member = User::factory()->memberOf($this->organization)->create();

    $this->plan = Plan::factory()->pro()->create();

    Subscription::factory()->for($this->organization)->for($this->owner)->create([
        'plan_id' => $this->plan->id,
        'seats_purchased' => 5,
    ]);
});

it('requires authentication', function () {
    $this->getJson('/api/labels')->assertStatus(401);
});

it('lists the system vocabulary alongside the organization custom terms', function () {
    (new LabelSeeder)->run();

    $shared = Label::factory()->forOrganization($this->organization, $this->owner)->create(['name' => 'Barangay Level']);
    $outsider = Label::factory()->forOrganization(Organization::factory()->create())->create(['name' => 'Someone Else']);

    $response = $this->signInAs($this->member)
        ->getJson('/api/labels')
        ->assertOk();

    $ids = collect($response->json('data'))->pluck('id');

    expect($ids)->toContain($shared->id)
        ->and($ids)->not->toContain($outsider->id)
        ->and($ids)->toHaveCount(27);
});

it('hides organization labels from a suspended member', function () {
    $shared = Label::factory()->forOrganization($this->organization, $this->owner)->create();

    $suspended = User::factory()
        ->memberOf($this->organization, User::ORG_ROLE_MEMBER, User::ORG_STATUS_SUSPENDED)
        ->create();

    $response = $this->signInAs($suspended)->getJson('/api/labels')->assertOk();

    expect(collect($response->json('data'))->pluck('id'))->not->toContain($shared->id);
});

it('filters the vocabulary by kind', function () {
    (new LabelSeeder)->run();

    $response = $this->signInAs($this->member)
        ->getJson('/api/labels?kind=thread_tag')
        ->assertOk();

    expect(collect($response->json('data'))->pluck('kind')->unique()->all())->toBe(['thread_tag']);
});

it('creates a custom label for the whole organization', function () {
    $response = $this->signInAs($this->member)
        ->postJson('/api/labels', [
            'kind' => LabelKind::ThreadTag->value,
            'name' => 'Small Claims',
        ])
        ->assertCreated();

    expect($response->json('data.scope'))->toBe('organization')
        ->and($response->json('data.slug'))->toBe('small-claims');

    $this->assertDatabaseHas('labels', [
        'slug' => 'small-claims',
        'organization_id' => $this->organization->id,
        'user_id' => $this->member->id,
    ]);
});

it('creates a personal label for a user who belongs to no organization', function () {
    $solo = User::factory()->create();
    Subscription::factory()->for($solo)->create(['plan_id' => $this->plan->id]);

    $response = $this->signInAs($solo)
        ->postJson('/api/labels', [
            'kind' => LabelKind::DocumentCategory->value,
            'name' => 'Client Handouts',
        ])
        ->assertCreated();

    expect($response->json('data.scope'))->toBe('personal');

    $this->assertDatabaseHas('labels', [
        'slug' => 'client-handouts',
        'organization_id' => null,
        'user_id' => $solo->id,
    ]);
});

it('rejects a custom label that shadows a system term', function () {
    (new LabelSeeder)->run();

    $this->signInAs($this->member)
        ->postJson('/api/labels', ['kind' => LabelKind::ThreadTag->value, 'name' => 'Urgent'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');
});

it('rejects a custom label that duplicates one the organization already has', function () {
    Label::factory()->threadTag()->forOrganization($this->organization, $this->owner)->create([
        'slug' => 'small-claims',
        'name' => 'Small Claims',
    ]);

    $this->signInAs($this->member)
        ->postJson('/api/labels', ['kind' => LabelKind::ThreadTag->value, 'name' => 'small claims'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');
});

it('refuses to edit or delete a system label', function () {
    (new LabelSeeder)->run();

    $system = Label::whereNull('organization_id')->whereNull('user_id')->firstOrFail();

    $this->signInAs($this->owner)->patchJson("/api/labels/{$system->id}", ['name' => 'Renamed'])->assertStatus(403);
    $this->signInAs($this->owner)->deleteJson("/api/labels/{$system->id}")->assertStatus(403);

    expect($system->fresh()->name)->not->toBe('Renamed');
});

it('lets an owner rename an organization label but keeps its slug fixed', function () {
    $label = Label::factory()->forOrganization($this->organization, $this->owner)->create([
        'slug' => 'barangay-level',
        'name' => 'Barangay Level',
    ]);

    $this->signInAs($this->owner)
        ->patchJson("/api/labels/{$label->id}", ['name' => 'Barangay Conciliation'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Barangay Conciliation')
        ->assertJsonPath('data.slug', 'barangay-level');
});

it('stops a rank-and-file member from deleting a shared label', function () {
    $label = Label::factory()->forOrganization($this->organization, $this->owner)->create();

    $this->signInAs($this->member)
        ->deleteJson("/api/labels/{$label->id}")
        ->assertStatus(403);

    $this->assertDatabaseHas('labels', ['id' => $label->id]);
});

it('detaches a deleted label without touching the documents that carried it', function () {
    $label = Label::factory()->forOrganization($this->organization, $this->owner)->create();
    $document = Document::factory()->for($this->member)->create();
    $document->syncLabels([$label], $this->member);

    $this->signInAs($this->owner)
        ->deleteJson("/api/labels/{$label->id}")
        ->assertStatus(204);

    $this->assertDatabaseMissing('labels', ['id' => $label->id]);
    $this->assertDatabaseHas('documents', ['id' => $document->id]);
    expect($document->fresh()->labels)->toHaveCount(0);
});

it('reports how many records carry each label', function () {
    $label = Label::factory()->forOrganization($this->organization, $this->owner)->create();
    Document::factory()->count(2)->for($this->member)->create()
        ->each(fn (Document $document) => $document->syncLabels([$label], $this->member));

    $response = $this->signInAs($this->owner)->getJson('/api/labels')->assertOk();

    $row = collect($response->json('data'))->firstWhere('id', $label->id);

    expect($row['usage_count'])->toBe(2)
        ->and($row['is_editable'])->toBeTrue();
});

it('marks shared labels as uneditable for a rank-and-file member', function () {
    $label = Label::factory()->forOrganization($this->organization, $this->owner)->create();

    $response = $this->signInAs($this->member)->getJson('/api/labels')->assertOk();

    expect(collect($response->json('data'))->firstWhere('id', $label->id)['is_editable'])->toBeFalse();
});

it('caps how many custom labels one organization may create', function () {
    Label::factory()
        ->count(Label::MAX_CUSTOM_PER_OWNER)
        ->forOrganization($this->organization, $this->owner)
        ->create();

    $this->signInAs($this->owner)
        ->postJson('/api/labels', ['kind' => LabelKind::DocumentCategory->value, 'name' => 'One Too Many'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');
});

it('rejects a name that produces no slug', function () {
    $this->signInAs($this->owner)
        ->postJson('/api/labels', ['kind' => LabelKind::ThreadTag->value, 'name' => '///'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');
});
