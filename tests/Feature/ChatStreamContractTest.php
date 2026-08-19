<?php

use App\Enums\ChatProvider;
use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Chat\ChatService;
use App\Support\WebSearchTrail;
use Generator;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall as ToolCallData;
use Laravel\Ai\Responses\Data\ToolResult as ToolResultData;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use Laravel\Ai\Streaming\Events\ToolResult as ToolResultEvent;

/**
 * The SSE wire format is a closed set: the client is told what it draws and
 * nothing else. These lock that down — a tool the UI has no surface for must
 * not reach the browser just because the model happened to call it, and the
 * turn's own account of what it did must be checked before it is streamed.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    Subscription::factory()->for($this->user)->create([
        'plan_id' => Plan::factory()->pro()->create()->id,
    ]);
});

/**
 * A ChatService whose stream replays the given events, optionally firing the
 * web-search trail callback first.
 *
 * @param  array<int, object>  $events
 * @param  array<int, array<string, mixed>>  $trail
 */
function fakeContractChatService(array $events, array $trail = [], array $notices = []): ChatService
{
    return new class($events, $trail, $notices) extends ChatService
    {
        public function __construct(
            protected array $events,
            protected array $trail,
            protected array $notices,
        ) {}

        public function stream(Conversation $conversation, string $question, ?callable $onStatus = null, array $attachmentIds = [], ?callable $onWebSearch = null): StreamableAgentResponse
        {
            Message::create([
                'conversation_id' => $conversation->id,
                'role' => MessageRole::User,
                'content' => $question,
            ]);

            foreach ($this->trail as $frame) {
                $onWebSearch?->__invoke($frame);
            }

            $events = $this->events;

            $response = new StreamableAgentResponse('test-invocation', function () use ($events): Generator {
                yield from $events;
            }, new Meta(provider: 'ollama', model: 'test-model'));

            $response->then(function (StreamedAgentResponse $streamed) use ($conversation): void {
                Message::create([
                    'conversation_id' => $conversation->id,
                    'role' => MessageRole::Assistant,
                    'content' => trim((string) $streamed->text) ?: 'ok',
                    'provider' => ChatProvider::Ollama,
                ]);
            });

            return $response;
        }

        public function pullToolNotices(): array
        {
            return $this->notices;
        }
    };
}

/** A tool call event, as the provider stream would deliver it. */
function contractToolCall(string $name, array $arguments): ToolCallEvent
{
    return new ToolCallEvent(
        id: 'call-'.$name,
        toolCall: new ToolCallData(id: 't1', name: $name, arguments: $arguments),
        timestamp: 1,
    );
}

/** A tool result event carrying the raw string the tool returned. */
function contractToolResult(string $name, string $result): ToolResultEvent
{
    return new ToolResultEvent(
        id: 'result-'.$name,
        toolResult: new ToolResultData(id: 't1', name: $name, arguments: [], result: $result),
        successful: true,
        error: null,
        timestamp: 2,
    );
}

function streamContract(array $events, array $trail = [], array $notices = []): string
{
    app()->instance(ChatService::class, fakeContractChatService($events, $trail, $notices));

    $conversation = Conversation::factory()->for(test()->user)->create();

    return test()->signInAs(test()->user)
        ->post("/api/conversations/{$conversation->id}/messages", ['message' => 'What is RA 6657?'])
        ->assertOk()
        ->streamedContent();
}

it('keeps a web search query and its results off the wire as a tool call', function () {
    $body = streamContract([
        contractToolCall('web_search', ['query' => 'prescriptive period under Rule 70']),
        contractToolResult('web_search', '{"findings":"internal"}'),
        new TextDelta(id: 'e3', messageId: 'm1', delta: 'The period is fifteen days.', timestamp: 3),
        new StreamEnd(id: 'e4', reason: 'stop', usage: new Usage(promptTokens: 1, completionTokens: 1), timestamp: 4),
    ]);

    expect($body)
        ->not->toContain('web_search')
        ->not->toContain('prescriptive period under Rule 70')
        ->not->toContain('internal')
        ->toContain('The period is fifteen days.');
});

it('sends a todo result as a count rather than the tool payload', function () {
    $body = streamContract([
        contractToolResult('create_todo', '{"items":[{"id":"1","title":"File the answer"},{"id":"2","title":"Serve the notice"}]}'),
        new StreamEnd(id: 'e2', reason: 'stop', usage: new Usage(promptTokens: 1, completionTokens: 1), timestamp: 2),
    ]);

    expect($body)
        ->toContain('"name":"create_todo"')
        ->toContain('"count":2')
        ->not->toContain('File the answer');
});

it('sends draft_letter as a bare signal, without the matter facts it was called with', function () {
    $body = streamContract([
        contractToolCall('draft_letter', ['request' => 'Demand P855,000 from Mr. Reyes of 14 Mabini St.']),
        new StreamEnd(id: 'e2', reason: 'stop', usage: new Usage(promptTokens: 1, completionTokens: 1), timestamp: 2),
    ]);

    expect($body)
        ->toContain('"name":"draft_letter"')
        ->not->toContain('Mabini')
        ->not->toContain('855,000');
});

it('streams the sites a search is reading and closes the trail when it ends', function () {
    $body = streamContract(
        [
            new TextDelta(id: 'e1', messageId: 'm1', delta: 'Fifteen days.', timestamp: 1),
            new StreamEnd(id: 'e2', reason: 'stop', usage: new Usage(promptTokens: 1, completionTokens: 1), timestamp: 2),
        ],
        trail: [
            WebSearchTrail::start('prescriptive period under Rule 70'),
            WebSearchTrail::reading([['url' => 'https://lawphil.net/rule-70', 'title' => null]]),
            WebSearchTrail::read([['index' => 1, 'url' => 'https://lawphil.net/rule-70', 'title' => 'Rule 70, Rules of Court']]),
            WebSearchTrail::done(1),
        ],
    );

    expect($body)
        ->toContain('event: web_search')
        ->toContain('"phase":"start"')
        ->toContain('"domain":"lawphil.net"')
        ->toContain('"phase":"read"')
        ->toContain('Rule 70, Rules of Court')
        ->toContain('"phase":"done"');
});

it('streams the notices raised against a reply that claimed what it did not do', function () {
    $body = streamContract(
        [
            new TextDelta(id: 'e1', messageId: 'm1', delta: 'I searched the web and confirmed this.', timestamp: 1),
            new StreamEnd(id: 'e2', reason: 'stop', usage: new Usage(promptTokens: 1, completionTokens: 1), timestamp: 2),
        ],
        notices: [['kind' => 'web_search', 'message' => 'This answer says the web was checked, but no web search ran for it.']],
    );

    expect($body)
        ->toContain('event: notice')
        ->toContain('"kind":"web_search"')
        ->toContain('no web search ran for it');
});

it('tells the client how many web sources the turn produced', function () {
    $body = streamContract([
        new TextDelta(id: 'e1', messageId: 'm1', delta: 'Fifteen days [Web 3].', timestamp: 1),
        new StreamEnd(id: 'e2', reason: 'stop', usage: new Usage(promptTokens: 1, completionTokens: 1), timestamp: 2),
    ]);

    expect($body)->toContain('"web_citations":0');
});
