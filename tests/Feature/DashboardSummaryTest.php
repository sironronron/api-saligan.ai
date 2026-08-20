<?php

use App\Enums\MessageRole;
use App\Enums\VettingRequestStatus;
use App\Models\Conversation;
use App\Models\LegalCase;
use App\Models\Message;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Todo;
use App\Models\User;
use App\Models\VettingRequest;

beforeEach(function () {
    $this->user = User::factory()->create();
    Subscription::factory()->for($this->user)->create([
        'plan_id' => Plan::factory()->pro()->create()->id,
    ]);
});

it('requires authentication', function () {
    $this->getJson('/api/dashboard/summary')->assertStatus(401);
});

it('returns the aggregated summary scoped to the authenticated user', function () {
    // Cases: one open and one closed for the user, plus one owned by someone else.
    LegalCase::factory()->for($this->user)->create(['status' => 'open']);
    LegalCase::factory()->for($this->user)->create(['status' => 'closed']);
    LegalCase::factory()->for(User::factory())->create();

    // Tasks: one pending + one completed on the user's conversation, plus a
    // task on a stranger's conversation that must not be counted.
    $conversation = Conversation::factory()->for($this->user)->create();
    Todo::factory()->create(['conversation_id' => $conversation->id, 'status' => 'pending']);
    Todo::factory()->create(['conversation_id' => $conversation->id, 'status' => 'completed']);
    Todo::factory()->create(['conversation_id' => Conversation::factory()->create()->id, 'status' => 'pending']);

    // Drafts: two letters on the user's conversation, plus one on a stranger's.
    $own = Conversation::factory()->for($this->user)->create();
    Message::factory()->create([
        'conversation_id' => $own->id,
        'role' => MessageRole::Assistant,
        'metadata' => ['letter_draft' => ['title' => 'Demand Letter']],
    ]);
    Message::factory()->create([
        'conversation_id' => $own->id,
        'role' => MessageRole::Assistant,
        'metadata' => ['letter_draft' => ['title' => 'Affidavit']],
    ]);
    Message::factory()->create([
        'conversation_id' => Conversation::factory()->for(User::factory())->create()->id,
        'role' => MessageRole::Assistant,
        'metadata' => ['letter_draft' => ['title' => 'Not mine']],
    ]);

    // Vetting: one open (under review) and one terminal (completed) for the user.
    VettingRequest::factory()->create(['submitter_id' => $this->user->id, 'status' => VettingRequestStatus::UnderReview]);
    VettingRequest::factory()->create(['submitter_id' => $this->user->id, 'status' => VettingRequestStatus::Completed]);
    VettingRequest::factory()->create(['submitter_id' => User::factory()->id, 'status' => VettingRequestStatus::UnderReview]);

    $response = $this->signInAs($this->user)
        ->getJson('/api/dashboard/summary')
        ->assertOk();

    $data = $response->json('data');

    // Cases: non-archived total and not-closed open count.
    expect($data['cases'])->toBe(['total' => 2, 'open' => 1]);

    // Tasks grouped by status.
    expect($data['tasks'])->toBe([
        'open' => 1,
        'pending' => 1,
        'on_going' => 0,
        'completed' => 1,
    ]);

    // Drafts: only the user's two letters, most recent first.
    expect($data['drafts']['total'])->toBe(2);
    expect($data['drafts']['recent'])->toHaveCount(2);
    expect($data['drafts']['recent'][0])->toHaveKeys(['message_id', 'title', 'created_at']);

    // Vetting: one open request, grouped by status.
    expect($data['vetting']['active'])->toBe(1);
    expect($data['vetting']['by_status'])->toBe(['under_review' => 1, 'completed' => 1]);

    // Personal workspace has no organization.
    expect($data['organization'])->toBe(['members' => 0, 'seats_used' => 0, 'seats_total' => 0]);

    // Usage meters carry used + limit.
    foreach (['messages', 'documents', 'active_cases'] as $key) {
        expect($data['usage'][$key])->toHaveKeys(['used', 'limit']);
        expect($data['usage'][$key]['used'])->toBeInt();
    }
});
