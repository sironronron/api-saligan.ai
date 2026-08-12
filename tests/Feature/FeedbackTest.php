<?php

use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    Subscription::factory()->for($this->user)->create([
        'plan_id' => Plan::factory()->pro()->create()->id,
    ]);
    $this->conversation = Conversation::factory()->for($this->user)->create();
});

it('requires authentication', function () {
    $this->postJson('/api/messages/foo/feedback', ['feedback' => 'up'])->assertStatus(401);
});

it('records a thumbs-up rating on an assistant message', function () {
    $message = Message::factory()->for($this->conversation)->create([
        'role' => MessageRole::Assistant,
    ]);

    $this->signInAs($this->user)
        ->postJson("/api/messages/{$message->id}/feedback", ['feedback' => 'up'])
        ->assertOk();

    $message->refresh();
    expect($message->feedback)->toBe('up')
        ->and($message->feedback_at)->not->toBeNull();
});

it('replaces an existing rating with the latest one', function () {
    $message = Message::factory()->for($this->conversation)->create([
        'role' => MessageRole::Assistant,
        'feedback' => 'up',
        'feedback_at' => now()->subDay(),
    ]);

    $this->signInAs($this->user)
        ->postJson("/api/messages/{$message->id}/feedback", ['feedback' => 'down'])
        ->assertOk();

    $message->refresh();
    expect($message->feedback)->toBe('down');
});

it('rejects invalid feedback values', function () {
    $message = Message::factory()->for($this->conversation)->create([
        'role' => MessageRole::Assistant,
    ]);

    $this->signInAs($this->user)
        ->postJson("/api/messages/{$message->id}/feedback", ['feedback' => 'maybe'])
        ->assertStatus(422);
});

it('clears feedback when deleted', function () {
    $message = Message::factory()->for($this->conversation)->create([
        'role' => MessageRole::Assistant,
        'feedback' => 'down',
        'feedback_at' => now(),
    ]);

    $this->signInAs($this->user)
        ->deleteJson("/api/messages/{$message->id}/feedback")
        ->assertOk();

    $message->refresh();
    expect($message->feedback)->toBeNull()
        ->and($message->feedback_at)->toBeNull();
});

it('forbids rating messages from another user\'s conversation', function () {
    $other = User::factory()->create();
    $otherConversation = Conversation::factory()->for($other)->create();
    $message = Message::factory()->for($otherConversation)->create([
        'role' => MessageRole::Assistant,
    ]);

    $this->signInAs($this->user)
        ->postJson("/api/messages/{$message->id}/feedback", ['feedback' => 'up'])
        ->assertStatus(403);
});
