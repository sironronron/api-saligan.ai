<?php

use App\Models\LegalCase;
use App\Models\Message;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Todo;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    Subscription::factory()->for($this->user)->create([
        'plan_id' => Plan::factory()->pro()->create()->id,
    ]);
});

it('requires authentication', function () {
    $this->getJson('/api/cases')->assertStatus(401);
});

it('creates a case from the intake form with an auto reference and conversation', function () {
    $response = $this->actingAs($this->user)->postJson('/api/cases', [
        'title' => 'Unpaid rent collection',
        'case_type' => 'legal',
        'status' => 'open',
        'priority' => 'high',
        'description' => 'Tenant failed to pay three months of rent.',
        'related_parties' => ['Juan Dela Cruz (tenant)'],
        'due_date' => '2026-09-01',
        'tags' => ['rent', 'collections'],
    ])->assertCreated();

    expect($response->json('data.reference'))->toMatch('/^CASE-\d{4}-\d{4}$/')
        ->and($response->json('data.conversation_id'))->not->toBeNull()
        ->and($response->json('data.conversations.0.purpose'))->toBe('General');

    $this->assertDatabaseHas('cases', ['id' => $response->json('data.id'), 'reference' => $response->json('data.reference')]);
    $this->assertDatabaseHas('conversations', ['case_id' => $response->json('data.id')]);
});

it('validates required intake fields', function () {
    $this->actingAs($this->user)
        ->postJson('/api/cases', ['title' => 'Missing type and status'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['case_type', 'status']);
});

it('accepts an explicit reference', function () {
    $this->actingAs($this->user)
        ->postJson('/api/cases', [
            'title' => 'Civil Case',
            'case_type' => 'legal',
            'status' => 'open',
            'reference' => 'CIV-2026-0001',
        ])
        ->assertCreated()
        ->assertJsonPath('data.reference', 'CIV-2026-0001');
});

it('lists only the authenticated users cases', function () {
    LegalCase::factory()->for($this->user)->create();
    LegalCase::factory()->for(User::factory())->create();

    $response = $this->actingAs($this->user)->getJson('/api/cases')->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and(array_key_exists('user_id', $response->json('data.0')))->toBeFalse();
});

it('excludes archived cases from the default list and includes them when requested', function () {
    $active = LegalCase::factory()->for($this->user)->create();
    $archived = LegalCase::factory()->for($this->user)->archived()->create();

    $this->actingAs($this->user)->getJson('/api/cases')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $active->id);

    $this->actingAs($this->user)->getJson('/api/cases?archived=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $archived->id);
});

it('filters cases by status and case type', function () {
    LegalCase::factory()->for($this->user)->create(['status' => 'open', 'case_type' => 'legal']);
    LegalCase::factory()->for($this->user)->create(['status' => 'closed', 'case_type' => 'legal']);
    LegalCase::factory()->for($this->user)->create(['status' => 'open', 'case_type' => 'hr']);

    $this->actingAs($this->user)->getJson('/api/cases?status=open')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->actingAs($this->user)->getJson('/api/cases?status=open&case_type=hr')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.case_type', 'hr');
});

it('searches across title, description, reference, and tags', function () {
    $byTitle = LegalCase::factory()->for($this->user)->create(['title' => 'Illegal dismissal claim']);
    $byTags = LegalCase::factory()->for($this->user)->create(['title' => 'Other', 'tags' => ['dismissal']]);
    $byReference = LegalCase::factory()->for($this->user)->create(['title' => 'Other', 'reference' => 'LAB-99-0001']);

    $this->actingAs($this->user)->getJson('/api/cases?search=dismissal')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->actingAs($this->user)->getJson('/api/cases?search=LAB-99')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $byReference->id);
});

it('reports task and message counts on the list', function () {
    $case = LegalCase::factory()->for($this->user)->create();
    $conversation = $case->conversation()->create(['user_id' => $this->user->id]);

    Message::factory()->for($conversation)->create(['role' => 'user', 'content' => 'hello']);
    Todo::factory()->for($conversation)->create(['status' => 'pending']);
    Todo::factory()->for($conversation)->create(['status' => 'completed']);

    $this->actingAs($this->user)->getJson('/api/cases')
        ->assertOk()
        ->assertJsonPath('data.0.messages_count', 1)
        ->assertJsonPath('data.0.open_tasks_count', 1)
        ->assertJsonPath('data.0.total_tasks_count', 2);
});

it('shows a case with its conversation threads, active messages, and tasks', function () {
    $case = LegalCase::factory()->for($this->user)->create();
    $conversation = $case->conversations()->create(['user_id' => $this->user->id, 'purpose' => 'General']);
    Message::factory()->for($conversation)->create(['role' => 'user', 'content' => 'What are my rights?']);
    Todo::factory()->for($conversation)->create(['title' => 'File the complaint']);

    $response = $this->actingAs($this->user)->getJson("/api/cases/{$case->id}")->assertOk();

    expect($response->json('data.conversation_id'))->toBe($conversation->id)
        ->and($response->json('data.active_conversation_id'))->toBe($conversation->id)
        ->and($response->json('data.conversations'))->toHaveCount(1)
        ->and($response->json('data.messages'))->toHaveCount(1)
        ->and($response->json('data.tasks.0.title'))->toBe('File the complaint');
});

it('creates additional conversation threads for a case and scopes messages by thread', function () {
    $case = LegalCase::factory()->for($this->user)->create();
    $general = $case->conversations()->create(['user_id' => $this->user->id, 'purpose' => 'General']);

    $drafting = $this->actingAs($this->user)
        ->postJson("/api/cases/{$case->id}/conversations", ['purpose' => 'Draft a letter'])
        ->assertCreated()
        ->json('data');

    expect($drafting['purpose'])->toBe('Draft a letter')
        ->and($drafting['case_id'])->toBe($case->id);

    Message::factory()->create(['conversation_id' => $general->id, 'role' => 'user', 'content' => 'general thread']);
    Message::factory()->create(['conversation_id' => $drafting['id'], 'role' => 'user', 'content' => 'drafting thread']);

    $response = $this->actingAs($this->user)->getJson("/api/cases/{$case->id}?conversation={$drafting['id']}")->assertOk();

    expect($response->json('data.active_conversation_id'))->toBe($drafting['id'])
        ->and($response->json('data.conversations'))->toHaveCount(2)
        ->and($response->json('data.messages.0.content'))->toBe('drafting thread');
});

it('forbids creating a conversation thread for another users case', function () {
    $case = LegalCase::factory()->for(User::factory())->create();

    $this->actingAs($this->user)
        ->postJson("/api/cases/{$case->id}/conversations", ['purpose' => 'Research'])
        ->assertForbidden();
});

it('updates case metadata', function () {
    $case = LegalCase::factory()->for($this->user)->create();

    $this->actingAs($this->user)
        ->patchJson("/api/cases/{$case->id}", [
            'title' => 'Renamed case',
            'status' => 'in_progress',
            'priority' => 'urgent',
            'tags' => ['urgent'],
        ])
        ->assertOk()
        ->assertJsonPath('data.title', 'Renamed case')
        ->assertJsonPath('data.status', 'in_progress');
});

it('archives, restores, and permanently deletes a case', function () {
    $case = LegalCase::factory()->for($this->user)->create();

    $this->actingAs($this->user)->deleteJson("/api/cases/{$case->id}")->assertOk();
    expect($case->fresh()->archived_at)->not->toBeNull();

    $this->actingAs($this->user)->postJson("/api/cases/{$case->id}/restore")->assertOk();
    expect($case->fresh()->archived_at)->toBeNull();

    $this->actingAs($this->user)
        ->deleteJson("/api/cases/{$case->id}/force", ['confirmation' => $case->title])
        ->assertNoContent();

    $this->assertDatabaseMissing('cases', ['id' => $case->id]);
});

it('refuses to permanently delete a case without a matching confirmation', function () {
    $case = LegalCase::factory()->for($this->user)->create();

    $this->actingAs($this->user)
        ->deleteJson("/api/cases/{$case->id}/force", ['confirmation' => 'wrong title'])
        ->assertStatus(422);

    $this->assertDatabaseHas('cases', ['id' => $case->id]);
});

it('duplicates a case into a fresh case', function () {
    $case = LegalCase::factory()->for($this->user)->create(['title' => 'Original case', 'tags' => ['debt']]);

    $response = $this->actingAs($this->user)->postJson("/api/cases/{$case->id}/duplicate")->assertCreated();

    expect($response->json('data.id'))->not->toBe($case->id)
        ->and($response->json('data.title'))->toBe('Copy of Original case')
        ->and($response->json('data.tags'))->toBe(['debt'])
        ->and($response->json('data.reference'))->not->toBe($case->reference);
});

it('does not allow access to another users case', function () {
    $case = LegalCase::factory()->for(User::factory())->create();

    $this->actingAs($this->user)->getJson("/api/cases/{$case->id}")->assertForbidden();
    $this->actingAs($this->user)->patchJson("/api/cases/{$case->id}", ['title' => 'Nope'])->assertForbidden();
    $this->actingAs($this->user)->deleteJson("/api/cases/{$case->id}")->assertForbidden();
});

it('does not leak tasks or messages between cases', function () {
    $first = LegalCase::factory()->for($this->user)->create();
    $second = LegalCase::factory()->for($this->user)->create();

    $firstConversation = $first->conversation()->create(['user_id' => $this->user->id]);
    $secondConversation = $second->conversation()->create(['user_id' => $this->user->id]);

    Message::factory()->for($firstConversation)->create(['role' => 'user', 'content' => 'only in first']);
    Todo::factory()->for($firstConversation)->create(['title' => 'only in first']);

    $response = $this->actingAs($this->user)->getJson("/api/cases/{$second->id}")->assertOk();

    expect($response->json('data.messages'))->toBeEmpty()
        ->and($response->json('data.tasks'))->toBeEmpty();
});
