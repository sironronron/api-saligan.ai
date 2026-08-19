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

it('lists only assistant messages carrying a letter draft', function () {
    $case = LegalCase::factory()->for($this->user)->create(['title' => 'Dela Cruz vs. Santos']);
    $conversation = Conversation::factory()->for($this->user)->create(['title' => 'Eviction case', 'case_id' => $case->id]);

    $draft = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => MessageRole::Assistant,
        'content' => "REPUBLIC OF THE PHILIPPINES\nCOMPLAINT FOR UNLAWFUL DETAINER",
        'metadata' => [
            'letter_draft' => [
                'content' => ['type' => 'doc', 'content' => []],
                'title' => 'Complaint for Unlawful Detainer',
            ],
        ],
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
        ->and($response->json('data.0.case_id'))->toBe($case->id)
        ->and($response->json('data.0.case_title'))->toBe('Dela Cruz vs. Santos')
        ->and($response->json('data.0.title'))->toBe('Complaint for Unlawful Detainer')
        ->and($response->json('data.0.content'))->toContain('COMPLAINT FOR UNLAWFUL DETAINER')
        ->and($response->json('data.0.letter_draft.content'))->toBe(['type' => 'doc', 'content' => []]);
});

it('does not include drafts from other users', function () {
    $other = Conversation::factory()->for(User::factory())->create();

    Message::factory()->create([
        'conversation_id' => $other->id,
        'role' => MessageRole::Assistant,
        'content' => 'COMPLAINT',
        'metadata' => ['letter_draft' => ['content' => ['type' => 'doc', 'content' => []]]],
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
        'content' => 'DEMAND LETTER',
        'metadata' => ['letter_draft' => ['content' => ['type' => 'doc', 'content' => []]]],
    ]);

    Message::factory()->create([
        'conversation_id' => $otherCase->id,
        'role' => MessageRole::Assistant,
        'content' => 'SUBPOENA',
        'metadata' => ['letter_draft' => ['content' => ['type' => 'doc', 'content' => []]]],
    ]);

    $this->signInAs($this->user)
        ->getJson('/api/generated-documents?case_id='.$case->id)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $draft->id);
});

it('shows a single generated document to its owner', function () {
    $case = LegalCase::factory()->for($this->user)->create(['title' => 'Dela Cruz vs. Santos']);
    $conversation = Conversation::factory()->for($this->user)->create(['title' => 'Eviction case', 'case_id' => $case->id]);

    $draft = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => MessageRole::Assistant,
        'content' => "DEMAND LETTER\n\nFor value received.",
        'metadata' => [
            'letter_draft' => [
                'content' => ['type' => 'doc', 'content' => []],
                'title' => 'Demand Letter',
            ],
        ],
    ]);

    $this->signInAs($this->user)
        ->getJson('/api/generated-documents/'.$draft->id)
        ->assertOk()
        ->assertJsonPath('data.id', $draft->id)
        ->assertJsonPath('data.title', 'Demand Letter')
        ->assertJsonPath('data.conversation_title', 'Eviction case')
        ->assertJsonPath('data.case_title', 'Dela Cruz vs. Santos')
        ->assertJsonPath('data.content', $draft->content)
        ->assertJsonPath('data.letter_draft.title', 'Demand Letter');
});

it('falls back to the first content line as the title when the draft has none', function () {
    $conversation = Conversation::factory()->for($this->user)->create(['title' => 'Eviction case']);

    $draft = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => MessageRole::Assistant,
        'content' => "DEMAND LETTER\n\nFor value received.",
        'metadata' => ['letter_draft' => ['content' => ['type' => 'doc', 'content' => []]]],
    ]);

    $this->signInAs($this->user)
        ->getJson('/api/generated-documents/'.$draft->id)
        ->assertOk()
        ->assertJsonPath('data.title', 'DEMAND LETTER');
});

it('does not show a generated document the caller cannot open', function () {
    $conversation = Conversation::factory()->for(User::factory())->create();

    $draft = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => MessageRole::Assistant,
        'content' => 'COMPLAINT',
        'metadata' => ['letter_draft' => ['content' => ['type' => 'doc', 'content' => []]]],
    ]);

    $this->signInAs($this->user)
        ->getJson('/api/generated-documents/'.$draft->id)
        ->assertStatus(403);
});

it('only exposes assistant messages that carry a letter draft as generated documents', function () {
    $conversation = Conversation::factory()->for($this->user)->create();

    $plain = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => MessageRole::Assistant,
        'content' => 'Under RA 6657, agrarian reform covers private agricultural lands.',
    ]);

    $this->signInAs($this->user)
        ->getJson('/api/generated-documents/'.$plain->id)
        ->assertStatus(404);
});

it('saves letter draft edits onto the message', function () {
    $conversation = Conversation::factory()->for($this->user)->create();

    $draft = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => MessageRole::Assistant,
        'content' => 'Here is your letter.',
        'metadata' => [
            'letter_draft' => [
                'content' => ['type' => 'doc', 'content' => []],
                'title' => 'Original Title',
            ],
        ],
    ]);

    $editedContent = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => []]]];

    $this->signInAs($this->user)
        ->patchJson('/api/messages/'.$draft->id.'/letter-draft', [
            'content' => $editedContent,
            'title' => 'Edited Title',
        ])
        ->assertOk()
        ->assertJsonPath('data.id', $draft->id)
        ->assertJsonPath('data.letter_draft.content', $editedContent)
        ->assertJsonPath('data.letter_draft.title', 'Edited Title');

    $this->assertSame($editedContent, $draft->refresh()->metadata['letter_draft']['content']);
    $this->assertSame('Edited Title', $draft->metadata['letter_draft']['title']);
});

it('requires a valid letter draft payload', function () {
    $conversation = Conversation::factory()->for($this->user)->create();

    $draft = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => MessageRole::Assistant,
        'content' => 'Here is your letter.',
        'metadata' => ['letter_draft' => ['content' => ['type' => 'doc', 'content' => []]]],
    ]);

    $this->signInAs($this->user)
        ->patchJson('/api/messages/'.$draft->id.'/letter-draft', ['content' => 'not an array'])
        ->assertStatus(422);
});

it('does not let a caller save edits to a letter they cannot open', function () {
    $conversation = Conversation::factory()->for(User::factory())->create();

    $draft = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => MessageRole::Assistant,
        'content' => 'Here is your letter.',
        'metadata' => ['letter_draft' => ['content' => ['type' => 'doc', 'content' => []]]],
    ]);

    $this->signInAs($this->user)
        ->patchJson('/api/messages/'.$draft->id.'/letter-draft', [
            'content' => ['type' => 'doc', 'content' => []],
        ])
        ->assertStatus(403);
});
