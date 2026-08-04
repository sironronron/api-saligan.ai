<?php

use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Todo;
use App\Models\User;
use App\Services\Chat\ChatService;
use App\Support\DraftingIntent;
use Generator;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall as ToolCallData;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;

function makeFakeChatService(array $events): ChatService
{
    return new class($events) extends ChatService
    {
        public function __construct(private array $events) {}

        public function stream(Conversation $conversation, string $question, ?callable $onStatus = null): StreamableAgentResponse
        {
            Message::create([
                'conversation_id' => $conversation->id,
                'role' => MessageRole::User,
                'content' => $question,
            ]);

            $events = $this->events;

            $response = new StreamableAgentResponse('test-invocation', function () use ($events): Generator {
                foreach ($events as $event) {
                    yield $event;
                }
            }, new Meta(provider: 'ollama', model: 'test-model'));

            $response->then(function (StreamedAgentResponse $streamed) use ($conversation): void {
                Message::create([
                    'conversation_id' => $conversation->id,
                    'role' => MessageRole::Assistant,
                    'content' => trim((string) $streamed->text),
                ]);
            });

            return $response;
        }
    };
}

function makeToolCallEvent(string $name, array $arguments): ToolCallEvent
{
    return new ToolCallEvent(
        id: 'tool-call-1',
        toolCall: new ToolCallData(id: 't1', name: $name, arguments: $arguments),
        timestamp: 1,
    );
}

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('stops the stream when the model calls the intake form tool', function () {
    $modelFields = [
        ['key' => 'plaintiff_name', 'label' => 'Your full name', 'type' => 'text', 'required' => true],
    ];

    $this->app->instance(ChatService::class, makeFakeChatService([
        makeToolCallEvent('request_intake_form', ['fields' => $modelFields]),
    ]));

    $conversation = Conversation::factory()->for($this->user)->create();

    $response = $this->actingAs($this->user)
        ->post("/api/conversations/{$conversation->id}/messages", [
            'message' => 'Draft me a reklamo for illegal occupation.',
        ]);

    $response->assertOk();

    $body = $response->streamedContent();

    expect($body)
        ->toContain('event: tool_call')
        ->toContain('"name":"request_intake_form"')
        ->toContain('"plaintiff_name"')
        ->toContain('event: done');
});

it('emits a synthetic intake form when the model skips the mandatory tool', function () {
    $this->app->instance(ChatService::class, makeFakeChatService([
        new TextDelta(id: 'a', messageId: 'm1', delta: 'I need your details first.', timestamp: 1),
        new StreamEnd(id: 'b', reason: 'stop', usage: new Usage(promptTokens: 5, completionTokens: 2), timestamp: 2),
    ]));

    $conversation = Conversation::factory()->for($this->user)->create();

    $response = $this->actingAs($this->user)
        ->post("/api/conversations/{$conversation->id}/messages", [
            'message' => 'Please draft a demand letter.',
        ]);

    $response->assertOk();

    $body = $response->streamedContent();

    expect($body)
        ->toContain('event: tool_call')
        ->toContain('"name":"request_intake_form"')
        ->toContain('"plaintiff_name"')
        ->toContain('event: done');
});

it('does not emit a synthetic intake form for non-drafting requests', function () {
    $this->app->instance(ChatService::class, makeFakeChatService([
        new TextDelta(id: 'a', messageId: 'm1', delta: 'The law states…', timestamp: 1),
        new StreamEnd(id: 'b', reason: 'stop', usage: new Usage(promptTokens: 5, completionTokens: 2), timestamp: 2),
    ]));

    $conversation = Conversation::factory()->for($this->user)->create();

    $response = $this->actingAs($this->user)
        ->post("/api/conversations/{$conversation->id}/messages", [
            'message' => 'Explain RA 6657, please.',
        ]);

    $response->assertOk();

    $body = $response->streamedContent();

    expect($body)
        ->not->toContain('"name":"request_intake_form"')
        ->toContain('event: done');
});

it('creates todos from the draft when the model skips the todo tool', function () {
    $draft = "### Next Steps\n\n1.  **File with the Court**: Submit the complaint to the Clerk of Court.\n2.  **Pay Filing Fees**: Pay the required filing fees.\n\n[Export as Word](/api/messages/abc/export/word)";

    $this->app->instance(ChatService::class, makeFakeChatService([
        new TextDelta(id: 'a', messageId: 'm1', delta: $draft, timestamp: 1),
        new StreamEnd(id: 'b', reason: 'stop', usage: new Usage(promptTokens: 5, completionTokens: 2), timestamp: 2),
    ]));

    $conversation = Conversation::factory()->for($this->user)->create();

    $response = $this->actingAs($this->user)
        ->post("/api/conversations/{$conversation->id}/messages", [
            'message' => "[Intake Form Submission]\nplaintiff_name: Juan Dela Cruz\nfacts: He built a house on my land.",
        ]);

    $response->assertOk();

    $body = $response->streamedContent();

    expect($body)
        ->toContain('event: tool_result')
        ->toContain('"name":"create_todo"');

    $this->assertDatabaseCount('todos', 2);
    $this->assertDatabaseHas('todos', ['title' => 'File with the Court: Submit the complaint to the Clerk of Court.']);
    $this->assertDatabaseHas('todos', ['title' => 'Pay Filing Fees: Pay the required filing fees.']);
});

it('does not create fallback todos when the model already called the todo tool', function () {
    $this->app->instance(ChatService::class, makeFakeChatService([
        new TextDelta(id: 'a', messageId: 'm1', delta: 'Your document is ready.', timestamp: 1),
        makeToolCallEvent('create_todo', ['items' => [['title' => 'File the complaint', 'status' => 'pending']]]),
        new StreamEnd(id: 'b', reason: 'stop', usage: new Usage(promptTokens: 5, completionTokens: 2), timestamp: 2),
    ]));

    $conversation = Conversation::factory()->for($this->user)->create();

    $this->actingAs($this->user)
        ->post("/api/conversations/{$conversation->id}/messages", [
            'message' => "[Intake Form Submission]\nplaintiff_name: Juan Dela Cruz",
        ])
        ->assertOk()
        ->streamedContent();

    $this->assertDatabaseCount('todos', 0);
});

it('extracts next steps from the drafted document', function () {
    $draft = "### Next Steps\n\n1.  **File with the Court**: Submit the complaint.\n2.  **Pay Filing Fees**: Pay the fees.\n\n[Export as PDF](/api/messages/abc/export/pdf)";

    $todos = DraftingIntent::fallbackTodos($draft);

    expect($todos)->toHaveCount(2)
        ->and($todos[0]['title'])->toBe('File with the Court: Submit the complaint.')
        ->and($todos[1]['status'])->toBe('pending');
});

it('caps overlong fallback todo titles to the column limit', function () {
    $draft = "### Next Steps\n\n1.  **File the Complaint**: ".str_repeat('Submit the complaint to the Clerk of Court and pay the fees. ', 10)."\n\n[Export as PDF](/api/messages/abc/export/pdf)";

    $todos = DraftingIntent::fallbackTodos($draft);

    expect(mb_strlen($todos[0]['title']))->toBeLessThanOrEqual(255);

    $todo = Todo::create([
        'conversation_id' => Conversation::factory()->for($this->user)->create()->id,
        'title' => $todos[0]['title'],
    ]);
    expect(mb_strlen($todo->title))->toBeLessThanOrEqual(255);
});

it('falls back to document-type todos when the draft has no steps section', function () {
    $draft = 'REPUBLIC OF THE PHILIPPINES … COMPLAINT FOR UNLAWFUL DETAINER / PRACTICE OF EJECTMENT … WHEREFORE …';

    $todos = DraftingIntent::fallbackTodos($draft);

    expect($todos)->not->toBeEmpty()
        ->and(array_column($todos, 'title'))->toContain('File the complaint with the proper court');
});

it('detects drafting intent from the user message', function () {
    expect(DraftingIntent::matches('Draft me a reklamo for illegal occupation.'))->toBeTrue()
        ->and(DraftingIntent::matches('Write a demand letter for unpaid debt.'))->toBeTrue()
        ->and(DraftingIntent::matches('Explain RA 6657, please.'))->toBeFalse();
});

it('provides default intake fields for the synthetic fallback', function () {
    $fields = DraftingIntent::defaultFields();

    expect($fields)->not->toBeEmpty();

    foreach (['plaintiff_name', 'defendant_name', 'facts', 'relief_sought'] as $key) {
        expect(array_column($fields, 'key'))->toContain($key);
    }
});
