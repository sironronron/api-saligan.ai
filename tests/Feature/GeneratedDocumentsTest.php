<?php

use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\LegalCase;
use App\Models\Message;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    Subscription::factory()->for($this->user)->create([
        'plan_id' => Plan::factory()->pro()->create()->id,
    ]);
});

it('requires authentication', function () {
    $this->getJson('/api/generated-documents')->assertStatus(401);
});

it('lists only assistant messages that are exportable drafts', function () {
    $conversation = Conversation::factory()->for($this->user)->create(['title' => 'Eviction case']);

    $draft = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => MessageRole::Assistant,
        'content' => "REPUBLIC OF THE PHILIPPINES\nCOMPLAINT FOR UNLAWFUL DETAINER\n\n[Download as Word](/api/messages/abc/export/word)\n[Download as PDF](/api/messages/abc/export/pdf)",
    ]);

    Message::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => MessageRole::Assistant,
        'content' => 'Under RA 6657, agrarian reform covers private agricultural lands.',
    ]);

    Message::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => MessageRole::User,
        'content' => 'Draft me a reklamo.',
    ]);

    $response = $this->signInAs($this->user)
        ->getJson('/api/generated-documents')
        ->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.id'))->toBe($draft->id)
        ->and($response->json('data.0.conversation_title'))->toBe('Eviction case')
        ->and($response->json('data.0.title'))->toContain('REPUBLIC OF THE PHILIPPINES')
        ->and($response->json('data.0.content'))->toContain('COMPLAINT FOR UNLAWFUL DETAINER');
});

it('does not include drafts from other users', function () {
    $other = Conversation::factory()->for(User::factory())->create();

    Message::factory()->create([
        'conversation_id' => $other->id,
        'role' => MessageRole::Assistant,
        'content' => "COMPLAINT\n\n[Download as Word](/api/messages/abc/export/word)",
    ]);

    $this->signInAs($this->user)
        ->getJson('/api/generated-documents')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('filters generated documents by case', function () {
    $case = LegalCase::factory()->for($this->user)->create();

    $inCase = Conversation::factory()->for($this->user)->create(['title' => 'Eviction case', 'case_id' => $case->id]);
    $otherCase = Conversation::factory()->for($this->user)->create(['title' => 'Unrelated case']);

    $draft = Message::factory()->create([
        'conversation_id' => $inCase->id,
        'role' => MessageRole::Assistant,
        'content' => "DEMAND LETTER\n\n[Download as Word](/api/messages/abc/export/word)\n[Download as PDF](/api/messages/abc/export/pdf)",
    ]);

    Message::factory()->create([
        'conversation_id' => $otherCase->id,
        'role' => MessageRole::Assistant,
        'content' => "SUBPOENA\n\n[Download as Word](/api/messages/def/export/word)",
    ]);

    $this->signInAs($this->user)
        ->getJson('/api/generated-documents?case_id='.$case->id)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $draft->id);
});
