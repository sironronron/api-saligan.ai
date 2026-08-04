<?php

use App\Enums\ChatProvider;
use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Chat\ChatService;
use Generator;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;

beforeEach(function () {
    $this->user = User::factory()->create();

    $fake = new class extends ChatService
    {
        public function __construct() {}

        public function stream(Conversation $conversation, string $question, ?callable $onStatus = null): StreamableAgentResponse
        {
            Message::create([
                'conversation_id' => $conversation->id,
                'role' => MessageRole::User,
                'content' => $question,
            ]);

            $response = new StreamableAgentResponse('test-invocation', function (): Generator {
                yield new TextDelta(id: 'a', messageId: 'm1', delta: 'Hello', timestamp: 1);
                yield new TextDelta(id: 'b', messageId: 'm1', delta: ' world.', timestamp: 2);
                yield new StreamEnd(id: 'c', reason: 'stop', usage: new Usage(promptTokens: 5, completionTokens: 2), timestamp: 3);
            }, new Meta(provider: 'ollama', model: 'test-model'));

            $response->then(function (StreamedAgentResponse $streamed) use ($conversation): void {
                $answer = trim((string) $streamed->text);

                Message::create([
                    'conversation_id' => $conversation->id,
                    'role' => MessageRole::Assistant,
                    'content' => $answer,
                    'provider' => ChatProvider::Ollama,
                ]);

                if ($conversation->title === null) {
                    $conversation->update(['title' => $answer]);
                }
            });

            return $response;
        }
    };

    $this->app->instance(ChatService::class, $fake);
});

it('streams the assistant answer and persists both messages', function () {
    $conversation = Conversation::factory()->for($this->user)->create();

    $response = $this->actingAs($this->user)
        ->post("/api/conversations/{$conversation->id}/messages", [
            'message' => 'Explain RA 6657, please.',
        ]);

    $response->assertOk();

    expect($response->headers->get('content-type'))->toContain('text/event-stream');

    $body = $response->streamedContent();

    expect($body)
        ->toContain('event: delta')
        ->toContain('"delta":"Hello"')
        ->toContain('"delta":" world."')
        ->toContain('event: done');

    $this->assertDatabaseHas('messages', [
        'conversation_id' => $conversation->id,
        'role' => MessageRole::User->value,
        'content' => 'Explain RA 6657, please.',
    ]);

    $this->assertDatabaseHas('messages', [
        'conversation_id' => $conversation->id,
        'role' => MessageRole::Assistant->value,
        'content' => 'Hello world.',
    ]);
});

it('auto-titles the conversation from the first answer', function () {
    $conversation = Conversation::factory()->for($this->user)->create([
        'title' => null,
    ]);

    $response = $this->actingAs($this->user)
        ->post("/api/conversations/{$conversation->id}/messages", [
            'message' => 'Explain RA 6657.',
        ])
        ->assertOk();

    // Consume the SSE stream so the then() callback (which persists the
    // assistant reply and auto-titles the conversation) actually runs.
    $response->streamedContent();

    $this->assertDatabaseHas('conversations', [
        'id' => $conversation->id,
        'title' => 'Hello world.',
    ]);
});

it('validates the message payload', function () {
    $conversation = Conversation::factory()->for($this->user)->create();

    $this->actingAs($this->user)
        ->postJson("/api/conversations/{$conversation->id}/messages", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('message');
});

it('forbids messaging another user conversation', function () {
    $conversation = Conversation::factory()->for(User::factory())->create();

    $this->actingAs($this->user)
        ->post("/api/conversations/{$conversation->id}/messages", [
            'message' => 'Hello',
        ])
        ->assertForbidden();
});
