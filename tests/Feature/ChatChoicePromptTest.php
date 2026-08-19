<?php

use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Chat\ChatService;
use App\Support\ChoicePrompt;
use Generator;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall as ToolCallData;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;

function makeChoiceChatService(array $events): ChatService
{
    return new class($events) extends ChatService
    {
        public function __construct(private array $events) {}

        public function stream(Conversation $conversation, string $question, ?callable $onStatus = null, array $attachmentIds = [], ?callable $onWebSearch = null): StreamableAgentResponse
        {
            $message = Message::create([
                'conversation_id' => $conversation->id,
                'role' => MessageRole::User,
                'content' => $question,
            ]);

            $this->createdUserMessageId = $message->id;

            $events = $this->events;

            $response = new StreamableAgentResponse('test-invocation', function () use ($events): Generator {
                foreach ($events as $event) {
                    yield $event;
                }
            }, new Meta(provider: 'ollama', model: 'test-model'));

            $response->then(function (StreamedAgentResponse $streamed) use ($conversation): void {
                $message = Message::create([
                    'conversation_id' => $conversation->id,
                    'role' => MessageRole::Assistant,
                    'content' => trim((string) $streamed->text),
                ]);

                $this->lastAssistantMessageId = $message->id;
            });

            return $response;
        }
    };
}

function makeChoiceToolCall(array $arguments): ToolCallEvent
{
    return new ToolCallEvent(
        id: 'tool-call-1',
        toolCall: new ToolCallData(id: 't1', name: 'ask_user_question', arguments: $arguments),
        timestamp: 2,
    );
}

/**
 * @return array<int, array<string, mixed>>
 */
function remedyQuestion(): array
{
    return [
        [
            'question' => 'Which remedy should I prepare first?',
            'header' => 'Remedy',
            'options' => [
                ['label' => 'Demand letter', 'description' => 'A formal demand sent before any filing'],
                ['label' => 'Barangay complaint', 'description' => 'Starts conciliation before the Lupon'],
            ],
        ],
    ];
}

beforeEach(function () {
    $this->user = User::factory()->create();
    Subscription::factory()->for($this->user)->create([
        'plan_id' => Plan::factory()->pro()->create()->id,
    ]);
});

it('stops the stream when the model puts a choice to the user', function () {
    $this->app->instance(ChatService::class, makeChoiceChatService([
        new TextDelta(id: 'a', messageId: 'm1', delta: 'You have two ways to go about the unpaid rent.', timestamp: 1),
        makeChoiceToolCall(['questions' => remedyQuestion()]),
        new TextDelta(id: 'b', messageId: 'm1', delta: 'I will proceed with the demand letter.', timestamp: 3),
        new StreamEnd(id: 'c', reason: 'stop', usage: new Usage(promptTokens: 5, completionTokens: 2), timestamp: 4),
    ]));

    $conversation = Conversation::factory()->for($this->user)->create();

    $response = $this->signInAs($this->user)
        ->post("/api/conversations/{$conversation->id}/messages", [
            'message' => 'What are my options against a tenant who has not paid rent?',
        ]);

    $response->assertOk();

    $body = $response->streamedContent();

    expect($body)
        ->toContain('event: tool_call')
        ->toContain('"name":"ask_user_question"')
        ->toContain('"status":"awaiting_choice"')
        ->toContain('"label":"Waiting for your choice"')
        ->toContain('Which remedy should I prepare first?')
        ->toContain('Barangay complaint')
        // What the model said before asking still reaches the user; what it
        // wrote after — an answer to a question nobody has answered — does not.
        ->toContain('You have two ways to go about the unpaid rent.')
        ->not->toContain('I will proceed with the demand letter.')
        ->toContain('event: done');
});

it('withholds a draft written past the question on a drafting request', function () {
    $draft = "[[DOCUMENT_START]]\nRe: Demand for Payment\n\nPay the unpaid rent within 15 days.\n[[DOCUMENT_END]]";

    $this->app->instance(ChatService::class, makeChoiceChatService([
        makeChoiceToolCall(['questions' => remedyQuestion()]),
        new TextDelta(id: 'a', messageId: 'm1', delta: $draft, timestamp: 3),
        new StreamEnd(id: 'b', reason: 'stop', usage: new Usage(promptTokens: 5, completionTokens: 2), timestamp: 4),
    ]));

    $conversation = Conversation::factory()->for($this->user)->create();

    $response = $this->signInAs($this->user)
        ->post("/api/conversations/{$conversation->id}/messages", [
            'message' => 'Draft something to make my tenant pay the back rent.',
        ]);

    $response->assertOk();

    $body = $response->streamedContent();

    expect($body)
        ->toContain('"name":"ask_user_question"')
        ->not->toContain('Pay the unpaid rent within 15 days')
        // A turn cut short by a question has no finished document, so it must
        // not be mined for next steps either.
        ->not->toContain('"name":"create_todo"')
        ->toContain('event: done');

    // Only the user's message survives: the model never finished a reply.
    $this->assertDatabaseCount('messages', 1);
    $this->assertDatabaseHas('messages', ['role' => MessageRole::User]);
    $this->assertDatabaseCount('todos', 0);
});

it('carries on when the question has nothing to choose between', function () {
    $this->app->instance(ChatService::class, makeChoiceChatService([
        makeChoiceToolCall(['questions' => [
            [
                'question' => 'Shall I proceed?',
                'header' => 'Proceed',
                'options' => [['label' => 'Yes', 'description' => 'Go ahead']],
            ],
        ]]),
        new TextDelta(id: 'a', messageId: 'm1', delta: 'Under Article 1673 the lessor may judicially eject the lessee.', timestamp: 3),
        new StreamEnd(id: 'b', reason: 'stop', usage: new Usage(promptTokens: 5, completionTokens: 2), timestamp: 4),
    ]));

    $conversation = Conversation::factory()->for($this->user)->create();

    $response = $this->signInAs($this->user)
        ->post("/api/conversations/{$conversation->id}/messages", [
            'message' => 'Can I evict a tenant who has not paid rent?',
        ]);

    $response->assertOk();

    $body = $response->streamedContent();

    // A one-option question is a dead end, so it is dropped and the model's
    // own answer stands rather than stranding the user on nothing to pick.
    expect($body)
        ->not->toContain('"name":"ask_user_question"')
        ->toContain('Under Article 1673 the lessor may judicially eject the lessee.')
        ->toContain('event: done');

    $this->assertDatabaseCount('messages', 2);
    $this->assertDatabaseHas('messages', ['role' => MessageRole::Assistant]);
});

it('drops a model-authored Other option before it reaches the client', function () {
    $this->app->instance(ChatService::class, makeChoiceChatService([
        makeChoiceToolCall(['questions' => [
            [
                'question' => 'Which venue do you want to start in?',
                'header' => 'Venue',
                'options' => [
                    ['label' => 'Barangay', 'description' => 'Conciliation before the Lupon'],
                    ['label' => 'MTC', 'description' => 'Unlawful detainer before the first-level court'],
                    ['label' => 'Other', 'description' => 'Something else entirely'],
                ],
            ],
        ]]),
        new StreamEnd(id: 'b', reason: 'stop', usage: new Usage(promptTokens: 5, completionTokens: 2), timestamp: 4),
    ]));

    $conversation = Conversation::factory()->for($this->user)->create();

    $response = $this->signInAs($this->user)
        ->post("/api/conversations/{$conversation->id}/messages", [
            'message' => 'Where should I bring my case against the tenant?',
        ]);

    $response->assertOk();

    // The UI supplies "Other" with a free-text box, so the model's own copy of
    // it would render twice.
    expect($response->streamedContent())
        ->toContain('"name":"ask_user_question"')
        ->toContain('Conciliation before the Lupon')
        ->not->toContain('Something else entirely');
});

it('normalizes a choice call into distinct, capped questions', function () {
    $questions = ChoicePrompt::normalize([
        [
            'question' => "  Which\n\nremedy should I prepare?  ",
            'header' => 'Remedy',
            'multi_select' => 'true',
            'options' => [
                ['label' => 'Demand letter', 'description' => 'Sent before filing'],
                ['label' => 'demand LETTER', 'description' => 'The same thing again'],
                ['label' => 'Barangay complaint', 'description' => 'Before the Lupon'],
                ['label' => 'None of the above', 'description' => 'Reserved for the UI'],
                ['label' => 'Ejectment suit', 'description' => 'Before the MTC'],
                ['label' => 'Small claims', 'description' => 'For the money alone'],
                ['label' => 'Criminal complaint', 'description' => 'One option too many'],
            ],
        ],
        // Dropped: nothing to choose between.
        ['question' => 'Proceed?', 'header' => 'Go', 'options' => [['label' => 'Yes']]],
        // Dropped: no question text.
        ['header' => 'Timing', 'options' => [['label' => 'Now'], ['label' => 'Later']]],
    ]);

    expect($questions)->toHaveCount(1)
        ->and($questions[0]['question'])->toBe('Which remedy should I prepare?')
        ->and($questions[0]['header'])->toBe('Remedy')
        ->and($questions[0]['id'])->toBe('remedy')
        ->and($questions[0]['multi_select'])->toBeTrue()
        ->and(array_column($questions[0]['options'], 'label'))
        ->toBe(['Demand letter', 'Barangay complaint', 'Ejectment suit', 'Small claims']);
});

it('caps a choice call at four questions and defaults multi_select to false', function () {
    $questions = ChoicePrompt::normalize(array_map(fn (int $index) => [
        'question' => "Question {$index}?",
        'header' => "Header {$index}",
        'options' => [['label' => 'A'], ['label' => 'B']],
    ], range(1, 6)));

    expect($questions)->toHaveCount(4)
        ->and($questions[0]['multi_select'])->toBeFalse()
        ->and($questions[0]['options'][0]['description'])->toBe('');
});

it('rejects arguments that carry no answerable question', function () {
    expect(ChoicePrompt::normalize(null))->toBe([])
        ->and(ChoicePrompt::normalize('questions'))->toBe([])
        ->and(ChoicePrompt::normalize([]))->toBe([])
        ->and(ChoicePrompt::normalize([['question' => 'Which one?', 'options' => 'not an array']]))->toBe([]);
});

it('recognizes the answer the client sends back', function () {
    expect(ChoicePrompt::isSubmission("[Choice Selection]\nQ: Which remedy?\nA: Demand letter"))->toBeTrue()
        ->and(ChoicePrompt::isSubmission('Draft a demand letter.'))->toBeFalse();
});
