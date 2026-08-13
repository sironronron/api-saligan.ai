<?php

use App\Enums\ChatProvider;
use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Chat\ChatService;
use Generator;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\UrlCitation;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Laravel\Ai\Streaming\Events\Citation;
use Laravel\Ai\Streaming\Events\ProviderToolEvent;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;

beforeEach(function () {
    $this->user = User::factory()->create();
    Subscription::factory()->for($this->user)->create([
        'plan_id' => Plan::factory()->pro()->create()->id,
    ]);

    $fake = new class extends ChatService
    {
        public function __construct() {}

        public function stream(Conversation $conversation, string $question, ?callable $onStatus = null, array $attachmentIds = []): StreamableAgentResponse
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

    $response = $this->signInAs($this->user)
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

it('streams status events with labels derived from the question', function () {
    $conversation = Conversation::factory()->for($this->user)->create();

    $response = $this->signInAs($this->user)
        ->post("/api/conversations/{$conversation->id}/messages", [
            'message' => 'Explain RA 6657, please.',
        ])
        ->assertOk();

    $body = $response->streamedContent();

    expect($body)
        ->toContain('event: status')
        ->toContain('"status":"composing"')
        // The topic travels once, on its own field, rather than being appended
        // to every step label — see ChatStatusTest for why.
        ->toContain('"label":"Writing your answer"')
        ->toContain('"topic":"RA 6657"');
});

it('auto-titles the conversation from the first answer', function () {
    $conversation = Conversation::factory()->for($this->user)->create([
        'title' => null,
    ]);

    $response = $this->signInAs($this->user)
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

it('streams web citations live as they are recorded', function () {
    $conversation = Conversation::factory()->for($this->user)->create();

    $fake = new class extends ChatService
    {
        public function __construct() {}

        public function stream(Conversation $conversation, string $question, ?callable $onStatus = null, array $attachmentIds = []): StreamableAgentResponse
        {
            $response = new StreamableAgentResponse('test-invocation', function (): Generator {
                yield new Citation('c1', 'm1', new UrlCitation('https://lawphil.net/ra-6657', 'RA 6657'), 1);
                yield new ProviderToolEvent(
                    't1',
                    'tool1',
                    'web_search_tool_result',
                    ['search_results' => [
                        ['url' => 'https://sc.judiciary.gov.ph/rule-43', 'title' => 'SC E-Library — Rule 43', 'snippet' => 'Rule 43 text.'],
                    ]],
                    'result_received',
                    2,
                );
                yield new StreamEnd('end', 'stop', new Usage(promptTokens: 1, completionTokens: 1), 3);
            }, new Meta(provider: 'anthropic', model: 'test-model'));

            return $response;
        }
    };

    $this->app->instance(ChatService::class, $fake);

    $response = $this->signInAs($this->user)
        ->post("/api/conversations/{$conversation->id}/messages", [
            'message' => 'Explain RA 6657.',
        ])
        ->assertOk();

    $body = $response->streamedContent();

    expect($body)
        ->toContain('event: citation')
        ->toContain('"url":"https://lawphil.net/ra-6657"')
        ->toContain('"url":"https://sc.judiciary.gov.ph/rule-43"')
        ->toContain('"title":"RA 6657"')
        ->toContain('"excerpt":"Rule 43 text."')
        ->toContain('"index":2');
});

it('deduplicates live web citations by url', function () {
    $conversation = Conversation::factory()->for($this->user)->create();

    $fake = new class extends ChatService
    {
        public function __construct() {}

        public function stream(Conversation $conversation, string $question, ?callable $onStatus = null, array $attachmentIds = []): StreamableAgentResponse
        {
            $response = new StreamableAgentResponse('test-invocation', function (): Generator {
                yield new Citation('c1', 'm1', new UrlCitation('https://lawphil.net/ra-6657', 'RA 6657'), 1);
                yield new Citation('c2', 'm1', new UrlCitation('https://lawphil.net/ra-6657', 'LawPhil'), 2);
                yield new StreamEnd('end', 'stop', new Usage(promptTokens: 1, completionTokens: 1), 3);
            }, new Meta(provider: 'anthropic', model: 'test-model'));

            return $response;
        }
    };

    $this->app->instance(ChatService::class, $fake);

    $response = $this->signInAs($this->user)
        ->post("/api/conversations/{$conversation->id}/messages", [
            'message' => 'Explain RA 6657.',
        ])
        ->assertOk();

    $body = $response->streamedContent();

    expect(substr_count($body, 'event: citation'))->toBe(1)
        ->and($body)->toContain('"index":1')
        ->and($body)->toContain('"title":"RA 6657"')
        ->and($body)->not->toContain('"title":"LawPhil"');
});

it('validates the message payload', function () {
    $conversation = Conversation::factory()->for($this->user)->create();

    $this->signInAs($this->user)
        ->postJson("/api/conversations/{$conversation->id}/messages", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('message');
});

it('forbids messaging another user conversation', function () {
    $conversation = Conversation::factory()->for(User::factory())->create();

    $this->signInAs($this->user)
        ->post("/api/conversations/{$conversation->id}/messages", [
            'message' => 'Hello',
        ])
        ->assertForbidden();
});
