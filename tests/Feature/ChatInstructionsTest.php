<?php

use App\Models\Conversation;
use App\Models\CrawledPage;
use App\Models\LegalChunk;
use App\Models\LegalSource;
use App\Models\Message;
use App\Models\SystemPrompt;
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

        public function instructionsFor(RetrievalResult $retrieval, Lab $provider): string
        {
            return $this->buildInstructions($retrieval, $provider, (string) Str::uuid());
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
        ->toContain('INTAKE FORM FIELD TEMPLATES')
        ->toContain('For a COMPLAINT / REKLAMO')
        ->toContain('call the create_todo');
});

it('instructs export links without re-pasting the document text', function () {
    $instructions = $this->chat->instructionsFor(new RetrievalResult(collect(), collect()), Lab::Ollama);

    expect($instructions)
        ->toContain('EXPORT INSTRUCTIONS')
        ->toContain('do NOT re-paste the document text')
        ->toContain('/export/word')
        ->toContain('/export/pdf');
});

it('forbids the assistant from claiming it cannot export', function () {
    $instructions = $this->chat->instructionsFor(new RetrievalResult(collect(), collect()), Lab::Ollama);

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
        ->toContain('/export/pdf');
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
