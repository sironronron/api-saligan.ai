<?php

use App\Models\Conversation;
use App\Models\CrawledPage;
use App\Models\LegalCase;
use App\Models\LegalChunk;
use App\Models\LegalSource;
use App\Models\Message;
use App\Models\SystemPrompt;
use App\Models\Template;
use App\Models\User;
use App\Services\Chat\ChatService;
use App\Services\Retrieval\RetrievalResult;
use Illuminate\Support\Str;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Laravel\Ai\Streaming\Events\TextDelta;

beforeEach(function () {
    SystemPrompt::factory()->create([
        'name' => 'saligan',
        'content' => 'You are Saligan, a Philippine legal research assistant.',
        'version' => 1,
        'is_active' => true,
    ]);

    $this->chat = new class extends ChatService
    {
        public function __construct() {}

        public function instructionsFor(RetrievalResult $retrieval, Lab $provider, bool $exportRequested = false): string
        {
            return $this->buildInstructions($retrieval, $provider, $exportRequested);
        }

        public function instructionsForCase(RetrievalResult $retrieval, Lab $provider, ?LegalCase $case, ?Template $template, bool $exportRequested = false): string
        {
            return $this->buildInstructions($retrieval, $provider, $exportRequested, $case, $template);
        }

        public function persistFor(Conversation $conversation, string $text, bool $appendExportLinks): void
        {
            $response = new StreamedAgentResponse(
                'invocation',
                collect([new TextDelta(id: 'a', messageId: 'm1', delta: $text, timestamp: 1)]),
                new Meta(provider: 'ollama', model: 'test-model'),
            );

            $this->persistAssistantResponse(
                $conversation,
                $response,
                new RetrievalResult(collect(), collect()),
                Lab::Ollama,
                (string) Str::uuid(),
                $appendExportLinks,
            );
        }

        public function exportRequestedFor(Conversation $conversation, string $prompt): bool
        {
            return $this->exportRequested($conversation, $prompt);
        }

        public function templateFor(Conversation $conversation, string $question): ?Template
        {
            return $this->resolveTemplate($conversation, $question);
        }
    };
});

it('instructs web search when no context is retrieved on a web-capable provider', function () {
    $instructions = $this->chat->instructionsFor(new RetrievalResult(collect(), collect()), Lab::Gemini);

    expect($instructions)
        ->toContain('WEB SEARCH FALLBACK')
        ->toContain('[Web N]')
        ->toContain('lawphil.net');
});

it('keeps the missing-information rules when no context is retrieved on a non-web provider', function () {
    $instructions = $this->chat->instructionsFor(new RetrievalResult(collect(), collect()), Lab::Ollama);

    expect($instructions)
        ->not->toContain('WEB SEARCH FALLBACK')
        ->toContain('RETRIEVED CONTEXT: No relevant material was retrieved');
});

it('includes the retrieved context block when sources are found', function () {
    $page = CrawledPage::factory()->for(LegalSource::factory())->create(['law_name' => 'RA No. 6657']);
    $chunk = LegalChunk::factory()->for($page)->create(['content' => 'Agrarian reform coverage.']);

    $legalChunks = LegalChunk::query()
        ->with('crawledPage.legalSource')
        ->whereKey($chunk->id)
        ->get();

    $instructions = $this->chat->instructionsFor(new RetrievalResult($legalChunks, collect()), Lab::Gemini);

    expect($instructions)
        ->toContain('=== RETRIEVED CONTEXT ===')
        ->toContain('[Source 1]')
        ->not->toContain('WEB SEARCH FALLBACK');
});

it('mandates the intake form before any legal document drafting', function () {
    $instructions = $this->chat->instructionsFor(new RetrievalResult(collect(), collect()), Lab::Ollama);

    expect($instructions)
        ->toContain('request_intake_form tool FIRST')
        ->toContain('Do NOT draft the document without first collecting the facts')
        ->toContain('Do NOT ask the user questions inline')
        ->toContain('always pass a document_type argument')
        ->toContain('INTAKE FORM FIELD TEMPLATES')
        ->toContain('For a COMPLAINT (only when the user wants to initiate a case before a')
        ->toContain('call the create_todo');
});

it('gives precise todo creation guidance for next steps', function () {
    $instructions = $this->chat->instructionsFor(new RetrievalResult(collect(), collect()), Lab::Ollama);

    expect($instructions)
        ->toContain('call the create_todo')
        ->toContain('verb-first')
        ->toContain('self-contained task title')
        ->toContain('MUST mirror the "Next Steps" checklist')
        ->toContain('Order the items by when the user should do them')
        ->toContain('Set priority (low/medium/high)')
        ->toContain('Set due_hint whenever the document states a period or')
        ->toContain('Merge near-duplicate steps');
});

it('lets the system append export links instead of the model writing them', function () {
    $instructions = $this->chat->instructionsFor(new RetrievalResult(collect(), collect()), Lab::Ollama, true);

    expect($instructions)
        ->toContain('EXPORT INSTRUCTIONS')
        ->toContain('do NOT re-paste the document text')
        ->toContain('NEVER write download URLs')
        ->toContain('example.com')
        ->toContain('[[DOCUMENT_END]]')
        ->toContain('the system appends')
        ->not->toContain('/export/word')
        ->not->toContain('/export/pdf');
});

it('does not instruct export links when the user did not ask for an export', function () {
    $instructions = $this->chat->instructionsFor(new RetrievalResult(collect(), collect()), Lab::Ollama);

    expect($instructions)
        ->toContain('do NOT append any download links')
        ->not->toContain('/export/word')
        ->not->toContain('/export/pdf');
});

it('detects an explicit export request in the prompt', function () {
    $conversation = Conversation::factory()->for(User::factory())->create();

    expect($this->chat->exportRequestedFor($conversation, 'Draft the demand letter and export to PDF'))
        ->toBeTrue()
        ->and($this->chat->exportRequestedFor($conversation, 'Draft the demand letter'))
        ->toBeFalse();
});

it('falls back to the prior user message when the prompt is an intake submission', function () {
    $conversation = Conversation::factory()->for(User::factory())->create();

    Message::create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'content' => 'Write the complaint and save it as a Word document',
    ]);

    expect($this->chat->exportRequestedFor(
        $conversation,
        '[Intake Form Submission] …user answers…',
    ))->toBeTrue();

    $conversation->messages()->where('content', 'like', 'Write the complaint%')->update(['content' => 'Write the complaint']);

    expect($this->chat->exportRequestedFor(
        $conversation,
        '[Intake Form Submission] …user answers…',
    ))->toBeFalse();
});

it('forbids the assistant from claiming it cannot export', function () {
    $instructions = $this->chat->instructionsFor(new RetrievalResult(collect(), collect()), Lab::Ollama, true);

    expect($instructions)
        ->toContain('Never say you cannot export')
        ->toContain('Never ask the user whether they want the document drafted')
        ->toContain('Do not say "let me know if you would like"');
});

it('appends export links to a persisted drafting message when the model omitted them', function () {
    $conversation = Conversation::factory()->for(User::factory())->create();

    $this->chat->persistFor($conversation, 'REPUBLIC OF THE PHILIPPINES … COMPLAINT …', true);

    $message = Message::where('conversation_id', $conversation->id)
        ->where('role', 'assistant')
        ->firstOrFail();

    expect($message->content)
        ->toContain('/export/word')
        ->toContain('/export/pdf')
        ->and($message->content)->toContain("/api/messages/{$message->id}/export/word")
        ->and($message->content)->toContain("/api/messages/{$message->id}/export/pdf");
});

it('does not duplicate export links when the draft already includes them', function () {
    $conversation = Conversation::factory()->for(User::factory())->create();

    $text = "COMPLAINT\n\n[Download as Word](/api/messages/abc/export/word)\n[Download as PDF](/api/messages/abc/export/pdf)";

    $this->chat->persistFor($conversation, $text, true);

    $message = Message::where('conversation_id', $conversation->id)
        ->where('role', 'assistant')
        ->firstOrFail();

    expect(substr_count($message->content, '/export/word'))->toBe(1)
        ->and(substr_count($message->content, '/export/pdf'))->toBe(1);
});

it('does not append export links to non-drafting turns', function () {
    $conversation = Conversation::factory()->for(User::factory())->create();

    $this->chat->persistFor($conversation, 'Under RA 6657, agrarian reform covers private lands.', false);

    $message = Message::where('conversation_id', $conversation->id)
        ->where('role', 'assistant')
        ->firstOrFail();

    expect($message->content)->not->toContain('/export/');
});

it('appends export links to a marked document even when no export was requested', function () {
    $conversation = Conversation::factory()->for(User::factory())->create();

    $text = "[[DOCUMENT_START]]\nREPUBLIC OF THE PHILIPPINES\n… COMPLAINT …\n[[DOCUMENT_END]]";

    $this->chat->persistFor($conversation, $text, false);

    $message = Message::where('conversation_id', $conversation->id)
        ->where('role', 'assistant')
        ->firstOrFail();

    expect($message->content)
        ->toContain('/export/word')
        ->toContain('/export/pdf');
});

it('strips placeholder export labels from an unmarked answer', function () {
    $conversation = Conversation::factory()->for(User::factory())->create();

    $text = "Here is the summary.\n\nEXPORT LINKS: [Word Document Download Link] | [PDF Exported Version]";

    $this->chat->persistFor($conversation, $text, false);

    $message = Message::where('conversation_id', $conversation->id)
        ->where('role', 'assistant')
        ->firstOrFail();

    expect($message->content)
        ->toContain('Here is the summary.')
        ->not->toContain('[Word Document Download Link]')
        ->not->toContain('[PDF Exported Version]')
        ->not->toContain('EXPORT LINKS')
        ->not->toContain('/export/');
});

it('includes standing Philippine legal correspondence conventions', function () {
    $instructions = $this->chat->instructionsFor(new RetrievalResult(collect(), collect()), Lab::Ollama);

    expect($instructions)
        ->toContain('PHILIPPINE LEGAL CORRESPONDENCE CONVENTIONS')
        ->toContain('SUBSCRIBED AND SWORN to before me')
        ->toContain('Ginoong/Ginang')
        ->toContain('Very truly yours');
});

it('wraps drafted documents in export boundary markers', function () {
    $instructions = $this->chat->instructionsFor(new RetrievalResult(collect(), collect()), Lab::Ollama);

    expect($instructions)
        ->toContain('=== DOCUMENT MARKERS ===')
        ->toContain('[[DOCUMENT_START]]')
        ->toContain('[[DOCUMENT_END]]')
        ->toContain('wrap ONLY that document')
        ->toContain('never be duplicated inside them')
        ->toContain('after [[DOCUMENT_END]]')
        ->toContain('Use them even when the user did not explicitly ask to export')
        ->toContain('omit the markers entirely');
});

it('injects case metadata into the instructions', function () {
    $case = LegalCase::factory()->create([
        'reference' => 'CASE-2026-0042',
        'related_parties' => ['Juan Dela Cruz (claimant)'],
    ]);

    $instructions = $this->chat->instructionsForCase(new RetrievalResult(collect(), collect()), Lab::Ollama, $case, null);

    expect($instructions)
        ->toContain('=== CASE CONTEXT ===')
        ->toContain('Case reference: CASE-2026-0042')
        ->toContain('Juan Dela Cruz (claimant)');
});

it('injects the selected template structure and conventions', function () {
    $template = Template::factory()->system()->legal()->create([
        'content' => 'PHILIPPINE DEMAND LETTER CONVENTIONS: follow block format.',
    ]);

    $instructions = $this->chat->instructionsForCase(new RetrievalResult(collect(), collect()), Lab::Ollama, null, $template);

    expect($instructions)
        ->toContain('=== SELECTED LETTER TEMPLATE ===')
        ->toContain('Demand Letter')
        ->toContain('Required structure, in order')
        ->toContain('PHILIPPINE DEMAND LETTER CONVENTIONS');
});

it('resolves a seeded template referenced by name in natural language', function () {
    $template = Template::factory()->system()->legal()->create([
        'name' => 'Barangay Complaint (Sumbong)',
        'legal_subtype' => 'barangay_complaint',
    ]);

    $conversation = Conversation::factory()->for(User::factory())->create();

    $resolved = $this->chat->templateFor(
        $conversation,
        'Please draft a letter following the "Barangay Complaint (Sumbong)" template using the facts in this case.',
    );

    expect($resolved)->not->toBeNull()
        ->and($resolved->id)->toBe($template->id);
});

it('passes the full template context when the template is referenced by name', function () {
    $template = Template::factory()->system()->create([
        'name' => 'Barangay Complaint (Sumbong)',
        'legal_subtype' => 'barangay_complaint',
        'content' => 'PHILIPPINE BARANGAY COMPLAINT CONVENTIONS: header under RA 7160.',
        'structure' => ['Republic of the Philippines', 'Narration of Facts', 'Relief Sought'],
        'placeholder_fields' => [
            ['key' => 'barangay_name', 'label' => 'Barangay / City / Municipality', 'required' => true],
            ['key' => 'complainant_name', 'label' => 'Complainant full name', 'required' => true],
        ],
    ]);

    $conversation = Conversation::factory()->for(User::factory())->create();

    $instructions = $this->chat->instructionsForCase(
        new RetrievalResult(collect(), collect()),
        Lab::Ollama,
        null,
        $this->chat->templateFor($conversation, 'using the "Barangay Complaint (Sumbong)" template'),
    );

    expect($instructions)
        ->toContain('Barangay Complaint (Sumbong)')
        ->toContain('Republic of the Philippines')
        ->toContain('Narration of Facts')
        ->toContain('Barangay / City / Municipality')
        ->toContain('PHILIPPINE BARANGAY COMPLAINT CONVENTIONS');
});

it('falls back to the case default template when none is referenced by name', function () {
    $template = Template::factory()->system()->legal()->create();

    $case = LegalCase::factory()->create(['default_template_id' => $template->id]);
    $conversation = Conversation::factory()->for($case->user)->create(['case_id' => $case->id]);

    $resolved = $this->chat->templateFor($conversation, 'Draft a demand letter for unpaid rent.');

    expect($resolved)->not->toBeNull()
        ->and($resolved->id)->toBe($template->id);
});
