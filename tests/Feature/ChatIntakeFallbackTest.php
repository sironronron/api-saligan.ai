<?php

use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Template;
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

function makeFailingChatService(): ChatService
{
    return new class extends ChatService
    {
        public function __construct() {}

        public function stream(Conversation $conversation, string $question, ?callable $onStatus = null): StreamableAgentResponse
        {
            $message = Message::create([
                'conversation_id' => $conversation->id,
                'role' => MessageRole::User,
                'content' => $question,
            ]);

            $this->createdUserMessageId = $message->id;

            throw new RuntimeException('Provider unavailable');
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
        new TextDelta(id: 'a', messageId: 'm1', delta: 'Here is your draft before we collect the facts…', timestamp: 2),
        new StreamEnd(id: 'b', reason: 'stop', usage: new Usage(promptTokens: 5, completionTokens: 2), timestamp: 3),
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
        ->not->toContain('Here is your draft before we collect the facts')
        ->toContain('event: done');

    $this->assertDatabaseCount('messages', 1);
    $this->assertDatabaseHas('messages', ['role' => MessageRole::User]);
});

it('emits a synthetic intake form when the model skips the mandatory tool', function () {
    $this->app->instance(ChatService::class, makeFakeChatService([
        new TextDelta(id: 'a', messageId: 'm1', delta: 'I need your details first.', timestamp: 1),
        new StreamEnd(id: 'b', reason: 'stop', usage: new Usage(promptTokens: 5, completionTokens: 2), timestamp: 2),
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

it('extracts checklist items with bold labels and colons', function () {
    $draft = "### Checklist\n\n**Gather Witnesses**: Do you have neighbors or other people who saw it?\n**Photos/Video**: If there is any video footage, get a copy immediately.\n\n[Export as PDF](/api/messages/abc/export/pdf)";

    $todos = DraftingIntent::fallbackTodos($draft);

    expect(array_column($todos, 'title'))
        ->toContain('Gather Witnesses: Do you have neighbors or other people who saw it?')
        ->toContain('Photos/Video: If there is any video footage, get a copy immediately.');
});

it('extracts plain label-detail checklist items and skips the intro line', function () {
    $draft = "### What to Do Now\n\nThe client should do the following:\nGather Witnesses: Ask your neighbors for statements.\nVisit the Barangay Hall: File the report with the Lupon.";

    $todos = DraftingIntent::fallbackTodos($draft);

    expect(array_column($todos, 'title'))
        ->toContain('Gather Witnesses: Ask your neighbors for statements.')
        ->toContain('Visit the Barangay Hall: File the report with the Lupon.')
        ->not->toContain('The client should do the following:');
});

it('does not treat a Sources section after Next Steps as todos', function () {
    $draft = "### Next Steps\n\n1.  File the complaint with the RTC.\n2.  Pay the filing fees.\n\nSources:\nRA No. 6657, Sec. 2\nG.R. No. 123456";

    $todos = DraftingIntent::fallbackTodos($draft);

    expect(array_column($todos, 'title'))
        ->toContain('File the complaint with the RTC.')
        ->toContain('Pay the filing fees.')
        ->not->toContain('RA No. 6657, Sec. 2')
        ->not->toContain('G.R. No. 123456');
});

it('stops extracting steps at a new markdown heading', function () {
    $draft = "### Next Steps\n\n1.  **Serve the demand letter**: with proof of receipt.\n\n### Attachments\n\nCertified copy of the TCT\nBarangay blotter";

    $todos = DraftingIntent::fallbackTodos($draft);

    expect(array_column($todos, 'title'))
        ->toContain('Serve the demand letter: with proof of receipt.')
        ->not->toContain('Certified copy of the TCT')
        ->not->toContain('Barangay blotter');
});

it('does not treat a bold Sources heading as a todo item', function () {
    $draft = "### Next Steps\n\n**Gather the land title**: get the certified TCT.\n\n**Sources:**\nRA No. 6657, Sec. 2";

    $todos = DraftingIntent::fallbackTodos($draft);

    expect(array_column($todos, 'title'))
        ->toContain('Gather the land title: get the certified TCT.')
        ->not->toContain('Sources')
        ->not->toContain('RA No. 6657');
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
        ->and(DraftingIntent::matches('I need to write DAR requesting a certified copy of my late father\'s CLOA.'))->toBeTrue()
        ->and(DraftingIntent::matches('Compose a petition for the barangay captain.'))->toBeTrue()
        ->and(DraftingIntent::matches('Explain RA 6657, please.'))->toBeFalse();
});

it('provides default intake fields for the synthetic fallback', function () {
    $fields = DraftingIntent::defaultFields();

    expect($fields)->not->toBeEmpty();

    foreach (['plaintiff_name', 'defendant_name', 'facts', 'relief_sought'] as $key) {
        expect(array_column($fields, 'key'))->toContain($key);
    }
});

it('detects the document category from the drafting request', function () {
    expect(DraftingIntent::documentTypeFor("I need to write DAR requesting a certified copy of my late father's CLOA."))
        ->toBe('government transaction letter')
        ->and(DraftingIntent::documentTypeFor('Send a demand letter for unpaid debt.'))->toBe('formal letter')
        ->and(DraftingIntent::documentTypeFor('Draft a deed of sale for our rice field.'))->toBe('deed')
        ->and(DraftingIntent::documentTypeFor('Prepare an affidavit of loss.'))->toBe('affidavit')
        ->and(DraftingIntent::documentTypeFor('Make a special power of attorney.'))->toBe('special power of attorney')
        ->and(DraftingIntent::documentTypeFor('Write a lease agreement.'))->toBe('agreement')
        ->and(DraftingIntent::documentTypeFor('Draft a reklamo for illegal occupation.'))->toBe('complaint')
        ->and(DraftingIntent::documentTypeFor('Explain RA 6657, please.'))->toBeNull();
});

it('maps document categories to the matching intake field sets', function () {
    $government = array_column(DraftingIntent::fieldsForDocumentType('government transaction letter'), 'key');

    expect($government)
        ->toContain('sender_name')
        ->toContain('agency_name')
        ->toContain('transaction_type')
        ->toContain('subject_matter')
        ->toContain('reference_number')
        ->not->toContain('defendant_name')
        ->not->toContain('court_preference');

    $affidavit = array_column(DraftingIntent::fieldsForDocumentType('affidavit'), 'key');

    expect($affidavit)
        ->toContain('affiant_name')
        ->toContain('statement_facts')
        ->toContain('place_of_execution');

    expect(DraftingIntent::fieldsForDocumentType('something unexpected'))
        ->toBe(DraftingIntent::defaultFields());
});

it('uses document-category fields in the synthetic intake form', function () {
    $this->app->instance(ChatService::class, makeFakeChatService([
        new TextDelta(id: 'a', messageId: 'm1', delta: 'I can draft that for you.', timestamp: 1),
        new StreamEnd(id: 'b', reason: 'stop', usage: new Usage(promptTokens: 5, completionTokens: 2), timestamp: 2),
    ]));

    $conversation = Conversation::factory()->for($this->user)->create();

    $response = $this->actingAs($this->user)
        ->post("/api/conversations/{$conversation->id}/messages", [
            'message' => "I need to write DAR requesting a certified copy of my late father's CLOA.",
        ]);

    $response->assertOk();

    $body = $response->streamedContent();

    expect($body)
        ->toContain('event: tool_call')
        ->toContain('"name":"request_intake_form"')
        ->toContain('"document_type":"government transaction letter"')
        ->toContain('"agency_name"')
        ->toContain('"sender_name"')
        ->toContain('"reference_number"')
        ->toContain('"relief_or_action_sought"')
        ->not->toContain('"defendant_name"')
        ->not->toContain('"court_preference"')
        ->toContain('event: done');
});

it('uses document-category fields when the model declares the type in the tool call', function () {
    $this->app->instance(ChatService::class, makeFakeChatService([
        makeToolCallEvent('request_intake_form', [
            'document_type' => 'affidavit',
            'fields' => [['key' => 'affiant_name', 'label' => 'Affiant', 'type' => 'text', 'required' => true]],
        ]),
        new StreamEnd(id: 'b', reason: 'stop', usage: new Usage(promptTokens: 5, completionTokens: 2), timestamp: 2),
    ]));

    $conversation = Conversation::factory()->for($this->user)->create();

    $response = $this->actingAs($this->user)
        ->post("/api/conversations/{$conversation->id}/messages", [
            'message' => 'Draft me an affidavit.',
        ]);

    $response->assertOk();

    $body = $response->streamedContent();

    expect($body)
        ->toContain('event: tool_call')
        ->toContain('"name":"request_intake_form"')
        ->toContain('"document_type":"affidavit"')
        ->toContain('"affiant_name"')
        ->toContain('"statement_facts"')
        ->toContain('"place_of_execution"')
        ->not->toContain('"plaintiff_name"')
        ->toContain('event: done');
});

it('derives intake fields from the selected template directive', function () {
    Template::factory()->system()->create([
        'legal_subtype' => 'barangay_complaint',
        'placeholder_fields' => [
            ['key' => 'barangay_name', 'label' => 'Barangay / City / Municipality', 'required' => true],
            ['key' => 'complainant_name', 'label' => 'Complainant full name', 'required' => true],
            ['key' => 'facts', 'label' => 'Narration of facts', 'required' => true],
        ],
    ]);

    $this->app->instance(ChatService::class, makeFakeChatService([
        makeToolCallEvent('request_intake_form', ['fields' => [['key' => 'anything']]]),
    ]));

    $conversation = Conversation::factory()->for($this->user)->create();

    $response = $this->actingAs($this->user)
        ->post("/api/conversations/{$conversation->id}/messages", [
            'message' => "[Template: barangay_complaint]\nDraft a complaint for my neighbor blocking the road.",
        ]);

    $response->assertOk();

    $body = $response->streamedContent();

    expect($body)
        ->toContain('event: tool_call')
        ->toContain('"name":"request_intake_form"')
        ->toContain('"barangay_name"')
        ->toContain('"complainant_name"')
        ->toContain('"facts"')
        ->not->toContain('"property_details"')
        ->not->toContain('"court_preference"');
});

it('derives intake fields from a template referenced by name', function () {
    Template::factory()->system()->create([
        'name' => 'Barangay Complaint (Sumbong)',
        'legal_subtype' => 'barangay_complaint',
        'placeholder_fields' => [
            ['key' => 'barangay_name', 'label' => 'Barangay / City / Municipality', 'required' => true],
            ['key' => 'complainant_name', 'label' => 'Complainant full name', 'required' => true],
            ['key' => 'facts', 'label' => 'Narration of facts', 'required' => true],
        ],
    ]);

    $this->app->instance(ChatService::class, makeFakeChatService([
        makeToolCallEvent('request_intake_form', ['fields' => [['key' => 'anything']]]),
    ]));

    $conversation = Conversation::factory()->for($this->user)->create();

    $response = $this->actingAs($this->user)
        ->post("/api/conversations/{$conversation->id}/messages", [
            'message' => 'Please draft a letter following the "Barangay Complaint (Sumbong)" template using the facts in this case.',
        ]);

    $response->assertOk();

    $body = $response->streamedContent();

    expect($body)
        ->toContain('event: tool_call')
        ->toContain('"name":"request_intake_form"')
        ->toContain('"barangay_name"')
        ->toContain('"complainant_name"')
        ->toContain('"facts"')
        ->not->toContain('"property_details"')
        ->not->toContain('"court_preference"');
});

it('rolls back the user message when the stream fails', function () {
    $this->app->instance(ChatService::class, makeFailingChatService());

    $conversation = Conversation::factory()->for($this->user)->create();

    $response = $this->actingAs($this->user)
        ->post("/api/conversations/{$conversation->id}/messages", [
            'message' => 'Draft a demand letter.',
        ]);

    $response->assertOk();

    $body = $response->streamedContent();

    expect($body)->toContain('event: error');

    $this->assertDatabaseCount('messages', 0);
});

it('rewrites fabricated export links to the persisted assistant message id', function () {
    $service = new class extends ChatService
    {
        public function __construct() {}

        public function links(string $text, string $id): string
        {
            return $this->withExportLinks($text, $id);
        }
    };

    $text = "Here is the draft.\n\n[Download as Word](/api/messages/fake-id/export/word)\n[Download as PDF](/api/messages/another-fake/export/pdf)";

    $result = $service->links($text, '019f-real-id-0000');

    expect($result)
        ->toContain('Here is the draft.')
        ->not->toContain('fake-id')
        ->not->toContain('another-fake')
        ->toContain('/api/messages/019f-real-id-0000/export/word')
        ->toContain('/api/messages/019f-real-id-0000/export/pdf');
});

it('strips fabricated example.com download links and appends the real links', function () {
    $service = new class extends ChatService
    {
        public function __construct() {}

        public function links(string $text, string $id): string
        {
            return $this->withExportLinks($text, $id);
        }
    };

    $text = "Confirmed. Here is your document.\n\n[Click here to download the Word file](https://example.com/download/word_demand_letter.docx)\n[Click here to download the PDF file](https://example.com/download/pdf_demand_letter.pdf)";

    $result = $service->links($text, '019f-real-id-0000');

    expect($result)
        ->toContain('Confirmed. Here is your document.')
        ->not->toContain('example.com')
        ->not->toContain('Click here')
        ->toContain('/api/messages/019f-real-id-0000/export/word')
        ->toContain('/api/messages/019f-real-id-0000/export/pdf');
});

it('strips fabricated download links when no export was requested', function () {
    $text = "Here is the draft.\n\n[Click here to download the Word file](https://example.com/download/word.docx)\n[Download as PDF](/api/messages/abc/export/pdf)";

    $result = DraftingIntent::stripExportLinks($text);

    expect($result)
        ->not->toContain('example.com')
        ->not->toContain('/export/');
});

it('rewrites placeholder export labels to the persisted assistant message id', function () {
    $service = new class extends ChatService
    {
        public function __construct() {}

        public function links(string $text, string $id): string
        {
            return $this->withExportLinks($text, $id);
        }
    };

    $text = "Here is your document.\n\nEXPORT LINKS: [Word Document Download Link] | [PDF Exported Version]";

    $result = $service->links($text, '019f-real-id-0000');

    expect($result)
        ->toContain('Here is your document.')
        ->not->toContain('[Word Document Download Link]')
        ->not->toContain('[PDF Exported Version]')
        ->not->toContain('EXPORT LINKS')
        ->toContain('/api/messages/019f-real-id-0000/export/word')
        ->toContain('/api/messages/019f-real-id-0000/export/pdf');
});

it('strips placeholder export labels from plain answers', function () {
    $text = "Summary text.\n\nEXPORT LINKS: [Word Document Download Link] | [PDF Exported Version]";

    $result = DraftingIntent::stripExportLinks($text);

    expect($result)
        ->toContain('Summary text.')
        ->not->toContain('[Word Document Download Link]')
        ->not->toContain('[PDF Exported Version]')
        ->not->toContain('EXPORT LINKS')
        ->not->toContain('/export/');
});
