<?php

use App\Ai\LetterDraftAgent;
use App\Ai\Tools\DraftLetterTool;
use App\Enums\MessageRole;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Chat\ChatService;
use App\Services\LetterDrafts\LetterDraftService;
use Generator;
use Laravel\Ai\Prompts\AgentPrompt;
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
use Laravel\Ai\Tools\Request;

function makeFakeChatServiceForLetters(array $events): ChatService
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

function makeDraftLetterToolCall(array $arguments): ToolCallEvent
{
    return new ToolCallEvent(
        id: 'tool-call-1',
        toolCall: new ToolCallData(id: 't1', name: 'draft_letter', arguments: $arguments),
        timestamp: 1,
    );
}

function makeDraftLetterToolResult(array $draft): ToolResultEvent
{
    return new ToolResultEvent(
        id: 'tool-result-1',
        toolResult: new ToolResultData(
            id: 't1',
            name: 'draft_letter',
            arguments: [],
            result: json_encode($draft, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ),
        successful: true,
        error: null,
        timestamp: 2,
    );
}

function makeTiptapDraft(string $title): array
{
    return [
        'content' => [
            'type' => 'doc',
            'content' => [
                ['type' => 'heading', 'attrs' => ['level' => 1], 'content' => [['type' => 'text', 'text' => $title]]],
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Dear Mr. Reyes,']]],
                ['type' => 'signature'],
            ],
        ],
        'title' => $title,
        'raw' => '{}',
    ];
}

beforeEach(function () {
    $this->user = User::factory()->create();
    Subscription::factory()->for($this->user)->create([
        'plan_id' => Plan::factory()->pro()->create()->id,
    ]);
});

it('streams a letter_draft event carrying the tiptap document', function () {
    $this->app->instance(ChatService::class, makeFakeChatServiceForLetters([
        makeDraftLetterToolCall(['request' => 'Draft a demand letter for unpaid rent.']),
        makeDraftLetterToolResult(makeTiptapDraft('Demand Letter')),
        new TextDelta(id: 'a', messageId: 'm1', delta: 'Your demand letter is ready — review it in the letter editor on the right.', timestamp: 3),
        new StreamEnd(id: 'b', reason: 'stop', usage: new Usage(promptTokens: 5, completionTokens: 2), timestamp: 4),
    ]));

    $conversation = Conversation::factory()->for($this->user)->create();

    $response = $this->signInAs($this->user)
        ->post("/api/conversations/{$conversation->id}/messages", [
            'message' => 'Draft a demand letter for unpaid rent.',
        ]);

    $response->assertOk();

    $body = $response->streamedContent();

    expect($body)
        ->toContain('event: tool_call')
        ->toContain('"name":"draft_letter"')
        ->toContain('event: letter_draft')
        ->toContain('"type":"doc"')
        ->toContain('"title":"Demand Letter"')
        ->toContain('Your demand letter is ready')
        ->toContain('event: done')
        // The document travels on letter_draft and nowhere else. Forwarding
        // draft_letter's tool_result as well put the same Tiptap JSON on the
        // wire twice, in a frame nothing rendered.
        ->not->toContain('"name":"draft_letter","count"')
        ->not->toContain('event: tool_result');
});

it('persists the assistant reply as the summary, not the document json', function () {
    $this->app->instance(ChatService::class, makeFakeChatServiceForLetters([
        makeDraftLetterToolCall(['request' => 'Draft a resignation letter, last day September 30.']),
        makeDraftLetterToolResult(makeTiptapDraft('Resignation Letter')),
        new TextDelta(id: 'a', messageId: 'm1', delta: 'Your resignation letter is ready.', timestamp: 3),
        new StreamEnd(id: 'b', reason: 'stop', usage: new Usage(promptTokens: 5, completionTokens: 2), timestamp: 4),
    ]));

    $conversation = Conversation::factory()->for($this->user)->create();

    $response = $this->signInAs($this->user)
        ->post("/api/conversations/{$conversation->id}/messages", [
            'message' => 'Draft a resignation letter.',
        ])
        ->assertOk();

    // Consuming the stream is what executes the whole turn, including the
    // persistence of both the user message and the assistant reply.
    $response->streamedContent();

    $this->assertDatabaseHas('messages', [
        'conversation_id' => $conversation->id,
        'role' => MessageRole::Assistant,
        'content' => 'Your resignation letter is ready.',
    ]);
});

it('drafts a sanitized tiptap document through the tool and records it', function () {
    LetterDraftAgent::fake([
        '{"type":"doc","content":[{"type":"paragraph","content":[{"type":"text","text":"Dear Manager,"}]},{"type":"signature"}]}',
    ]);

    $recorded = null;

    $tool = new DraftLetterTool(
        caseContext: '',
        user: $this->user,
        onDrafted: function (array $draft) use (&$recorded): void {
            $recorded = $draft;
        },
    );

    $result = $tool->handle(new Request([
        'request' => 'Draft a resignation letter, last day September 30.',
    ]));

    $decoded = json_decode($result, true);

    expect($decoded['content']['type'])->toBe('doc')
        ->and($decoded['title'])->toBe('Dear Manager,')
        ->and(collect($decoded['content']['content'])->last()['type'])->toBe('signature')
        ->and($recorded['title'])->toBe('Dear Manager,');
});

it('includes the case context in the drafting request', function () {
    LetterDraftAgent::fake([
        '{"type":"doc","content":[{"type":"paragraph","content":[{"type":"text","text":"Body"}]}]}',
    ]);

    $tool = new DraftLetterTool(
        caseContext: 'Juan Dela Cruz is the farmer-owner of a 2-hectare rice farm in Laguna.',
        user: $this->user,
        onDrafted: function (array $draft): void {
            //
        },
    );

    $tool->handle(new Request([
        'request' => 'Draft a demand letter.',
    ]));

    LetterDraftAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'Juan Dela Cruz is the farmer-owner'));
});

it('salvages the raw text into a document when the letter agent returns unusable output', function () {
    LetterDraftAgent::fake(['Dear Manager,

# Demand Letter

Please settle the outstanding rent of P25,000 within 15 days.

Sincerely,']);

    $tool = new DraftLetterTool(
        caseContext: '',
        user: $this->user,
        onDrafted: function (array $draft): void {
            //
        },
    );

    $result = json_decode($tool->handle(new Request(['request' => 'Draft a letter.'])), true);

    expect($result['content']['type'])->toBe('doc')
        ->and($result['content']['content'][0]['content'][0]['text'])->toBe('Dear Manager,')
        ->and($result['content']['content'][1]['type'])->toBe('heading')
        ->and($result['content']['content'][1]['content'][0]['text'])->toBe('Demand Letter')
        ->and(collect($result['content']['content'])->last()['type'])->toBe('signature')
        ->and($result['title'])->toBe('Dear Manager,');
});

it('falls back to a blank document when the letter agent returns no output at all', function () {
    LetterDraftAgent::fake(fn (): string => '   ');

    $tool = new DraftLetterTool(
        caseContext: '',
        user: $this->user,
        onDrafted: function (array $draft): void {
            //
        },
    );

    $result = json_decode($tool->handle(new Request(['request' => 'Draft a letter.'])), true);

    expect($result['content']['type'])->toBe('doc')
        ->and($result['content']['content'][0])->toMatchArray(['type' => 'paragraph', 'content' => []])
        ->and(collect($result['content']['content'])->last()['type'])->toBe('signature');
});

it('retries the drafting call when the agent returns an empty reply, then ships the good draft', function () {
    $calls = 0;

    LetterDraftAgent::fake(function () use (&$calls): string {
        $calls++;

        return $calls === 1
            ? ''
            : '{"type":"doc","content":[{"type":"paragraph","content":[{"type":"text","text":"Dear Manager,"}]},{"type":"signature"}]}';
    });

    $tool = new DraftLetterTool(
        caseContext: '',
        user: $this->user,
        onDrafted: function (array $draft): void {
            //
        },
    );

    $result = json_decode($tool->handle(new Request(['request' => 'Draft a resignation letter.'])), true);

    expect($calls)->toBe(2)
        ->and($result['content']['content'][0]['content'][0]['text'])->toBe('Dear Manager,')
        ->and(collect($result['content']['content'])->last()['type'])->toBe('signature');
});

it('exposes the letter_draft metadata on the message resource', function () {
    $draft = makeTiptapDraft('Demand Letter');

    $conversation = Conversation::factory()->for($this->user)->create();

    $message = Message::create([
        'conversation_id' => $conversation->id,
        'role' => MessageRole::Assistant,
        'content' => 'Your demand letter is ready.',
        'metadata' => ['letter_draft' => $draft],
    ]);

    $resource = MessageResource::make($message)->resolve();

    expect($resource['letter_draft']['title'])->toBe('Demand Letter')
        ->and($resource['letter_draft']['content']['type'])->toBe('doc')
        ->and($resource['letter_draft'])->toMatchArray($draft);
});

it('converts an inline markdown letter into an editor document', function () {
    $markdown = <<<'MD'
MERIDIAN PRECISION MANUFACTURING LTD.
[Company Address]

**RE: FORMAL DEMAND**

Dear Sirs,

Please reverse the suspension of our certificate within **15 days**.

- Retain the audit evidence
- Correct the record

Sincerely,
MD;

    $draft = app(LetterDraftService::class)->fromMarkdown($markdown, 'draft a demand letter.');

    $nodes = $draft['content']['content'];

    expect($draft['content']['type'])->toBe('doc')
        ->and($draft['title'])->toBe('MERIDIAN PRECISION MANUFACTURING LTD. [Company Address]')
        ->and($nodes[0]['type'])->toBe('paragraph')
        ->and($nodes[1]['type'])->toBe('paragraph')
        ->and($nodes[1]['content'][0]['marks'])->toBe([['type' => 'bold']])
        ->and($nodes[2]['type'])->toBe('paragraph')
        ->and($nodes[3]['type'])->toBe('paragraph')
        ->and($nodes[4]['type'])->toBe('bulletList')
        ->and($nodes[4]['content'][0]['content'][0]['content'][0]['text'])->toBe('Retain the audit evidence')
        ->and(collect($nodes)->last()['type'])->toBe('signature');
});

it('recovers an inline letter into the editor when the model never called the tool', function () {
    $recovered = null;

    $events = [
        new TextDelta(id: 'a', messageId: 'm1', delta: 'Here is your demand letter.', timestamp: 1),
        new TextDelta(id: 'b', messageId: 'm1', delta: "\n\n[[DOCUMENT_START]]\n**MERIDIAN PRECISION MANUFACTURING LTD.**\n\nDear Sirs,\n\nPlease pay within 15 days.\n[[DOCUMENT_END]]", timestamp: 2),
        new StreamEnd(id: 'c', reason: 'stop', usage: new Usage(promptTokens: 5, completionTokens: 2), timestamp: 3),
    ];

    $this->app->instance(ChatService::class, new class($events, $recovered) extends ChatService
    {
        public function __construct(private array $events, private ?array &$recovered) {}

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
                Message::create([
                    'conversation_id' => $conversation->id,
                    'role' => MessageRole::Assistant,
                    'content' => trim((string) $streamed->text),
                ]);
            });

            return $response;
        }

        public function recordRecoveredLetter(array $draft): void
        {
            $this->recovered = $draft;
        }
    });

    $conversation = Conversation::factory()->for($this->user)->create();

    $response = $this->signInAs($this->user)
        ->post("/api/conversations/{$conversation->id}/messages", [
            'message' => 'draft a demand letter.',
        ]);

    $response->assertOk();

    $body = $response->streamedContent();

    expect($body)
        ->toContain('event: letter_draft')
        ->toContain('"type":"doc"')
        ->toContain('MERIDIAN PRECISION MANUFACTURING LTD.')
        ->and($recovered)->not->toBeNull()
        ->and($recovered['content']['type'])->toBe('doc')
        ->and(collect($recovered['content']['content'])->last()['type'])->toBe('signature');
});
