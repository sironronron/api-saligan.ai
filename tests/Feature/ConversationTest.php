<?php

use App\Enums\ChatProvider;
use App\Models\Conversation;
use App\Models\LegalCase;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('requires authentication', function () {
    $this->getJson('/api/conversations')->assertStatus(401);
});

it('lists only the authenticated user conversations', function () {
    $own = Conversation::factory()->for($this->user)->create();
    Conversation::factory()->for(User::factory())->create();

    $response = $this->actingAs($this->user)
        ->getJson('/api/conversations')
        ->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.id'))->toBe($own->id);
});

it('creates a conversation with the default provider', function () {
    $response = $this->actingAs($this->user)
        ->postJson('/api/conversations', ['title' => 'RA 6657 research'])
        ->assertCreated();

    expect($response->json('data.title'))->toBe('RA 6657 research')
        ->and($response->json('data.provider'))->toBe(ChatProvider::Ollama->value);

    $this->assertDatabaseHas('conversations', [
        'user_id' => $this->user->id,
        'provider' => ChatProvider::Ollama->value,
    ]);
});

it('creates a conversation with a purpose', function () {
    $response = $this->actingAs($this->user)
        ->postJson('/api/conversations', ['purpose' => 'Legal research'])
        ->assertCreated();

    expect($response->json('data.purpose'))->toBe('Legal research')
        ->and($response->json('data.title'))->toBe('Legal research');
});

it('forbids attaching a conversation to another users case', function () {
    $case = LegalCase::factory()->for(User::factory())->create();

    $this->actingAs($this->user)
        ->postJson('/api/conversations', ['case_id' => $case->id])
        ->assertForbidden();
});

it('creates a conversation with an explicit provider', function () {
    $response = $this->actingAs($this->user)
        ->postJson('/api/conversations', ['provider' => 'gemini'])
        ->assertCreated();

    expect($response->json('data.provider'))->toBe('gemini');
});

it('rejects an invalid provider', function () {
    $this->actingAs($this->user)
        ->postJson('/api/conversations', ['provider' => 'claude'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('provider');
});

it('shows a conversation with its messages', function () {
    $conversation = Conversation::factory()->for($this->user)
        ->hasMessages(2)
        ->create();

    $response = $this->actingAs($this->user)
        ->getJson("/api/conversations/{$conversation->id}")
        ->assertOk();

    expect($response->json('data.messages'))->toHaveCount(2);
});

it('forbids showing another user conversation', function () {
    $conversation = Conversation::factory()->for(User::factory())->create();

    $this->actingAs($this->user)
        ->getJson("/api/conversations/{$conversation->id}")
        ->assertForbidden();
});

it('updates the conversation title and provider', function () {
    $conversation = Conversation::factory()->for($this->user)->create();

    $this->actingAs($this->user)
        ->patchJson("/api/conversations/{$conversation->id}", [
            'title' => 'Renamed',
            'provider' => 'gemini',
        ])
        ->assertOk()
        ->assertJsonPath('data.title', 'Renamed')
        ->assertJsonPath('data.provider', 'gemini');
});

it('deletes a conversation', function () {
    $conversation = Conversation::factory()->for($this->user)->create();

    $this->actingAs($this->user)
        ->deleteJson("/api/conversations/{$conversation->id}")
        ->assertNoContent();

    $this->assertDatabaseMissing('conversations', ['id' => $conversation->id]);
});

it('forbids deleting another user conversation', function () {
    $conversation = Conversation::factory()->for(User::factory())->create();

    $this->actingAs($this->user)
        ->deleteJson("/api/conversations/{$conversation->id}")
        ->assertForbidden();
});
