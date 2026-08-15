<?php

use App\Models\Advisory;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\SystemPrompt;
use App\Models\User;
use App\Services\Chat\ChatService;
use App\Services\Retrieval\RetrievalResult;
use Database\Seeders\SystemPromptSeeder;
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

        public function staticFor(): string
        {
            return $this->staticInstructions();
        }

        public function flaggedNoticeFor(Conversation $conversation): ?string
        {
            return $this->flaggedAdvisoriesNotice($conversation);
        }

        public function persistFor(Conversation $conversation, string $assistantMessageId): void
        {
            $response = new StreamedAgentResponse(
                'invocation',
                collect([new TextDelta(id: 'a', messageId: 'm1', delta: 'Here is the answer.', timestamp: 1)]),
                new Meta(provider: 'ollama', model: 'test-model'),
            );

            $this->persistAssistantResponse(
                $conversation,
                $response,
                new RetrievalResult(collect(), collect()),
                Lab::Ollama,
                $assistantMessageId,
            );
        }
    };
});

it('tells the model how to flag what the user might miss', function () {
    $instructions = $this->chat->staticFor();

    expect($instructions)->toContain('flag_advisories')
        ->and($instructions)->toContain('FLAGGING WHAT THE USER MIGHT MISS')
        // The point of the tool is that these stop being prose the reader
        // scrolls past, so the reply must not carry them twice.
        ->and($instructions)->toContain('do NOT also write those points out as a "Caveats" section');
});

it('forbids manufacturing a caveat to have something to file', function () {
    // A mandatory-feeling tool call is the one way this feature could invent
    // facts about a user's matter, so the prohibition has to be explicit.
    $instructions = $this->chat->staticFor();

    expect($instructions)->toContain('NEVER MANUFACTURE ONE')
        ->and($instructions)->toContain('as serious an error as a fabricated citation')
        ->and($instructions)->toContain('no call is a perfectly good outcome');
});

it('keeps the prose caveats section as the fallback when the tool never ran', function () {
    // Routing caveats out of the reply must not be able to lose them: a turn
    // where the tool was not offered still has to say them somewhere.
    $instructions = $this->chat->staticFor();

    expect($instructions)->toContain('If the tool is NOT available to you on this turn, or the call fails')
        ->and($instructions)->toContain('write them out as the "Caveats and next steps" section');
});

it('does not leave the persona demanding a caveats section the tool now carries', function () {
    // Against the real seeded persona, not the stub above. The persona and the
    // advisory block sit ~50k characters apart in the composed prompt; if the
    // persona still called section 4 unconditional, the model would write the
    // caveats out AND file them on the same turn.
    SystemPrompt::query()->delete();
    $this->seed(SystemPromptSeeder::class);

    $instructions = $this->chat->staticFor();

    expect($instructions)->not->toContain('five-part ANSWER STRUCTURE')
        ->and($instructions)->toContain('Where they appear: filed through flag_advisories, this section is NOT written out in the reply')
        // The persona must agree that an empty turn files nothing, or the two
        // blocks pull in opposite directions on the hallucination question.
        ->and($instructions)->toContain('omit the section AND make no tool call');
});

it('carries no already-flagged notice on a fresh conversation', function () {
    $conversation = Conversation::factory()->for(User::factory())->create();

    expect($this->chat->flaggedNoticeFor($conversation))->toBeNull();
});

it('lists what this conversation already flagged so it is not raised twice', function () {
    $conversation = Conversation::factory()->for(User::factory())->create();

    Advisory::factory()->for($conversation)->create([
        'title' => 'The date of receipt of the demand letter is unconfirmed',
    ]);
    // An answered advisory still belongs on the list: re-raising a point the
    // user waved off is the most annoying duplicate of all.
    Advisory::factory()->for($conversation)->answered()->create([
        'title' => 'The lot may be under agrarian reform coverage',
    ]);

    $notice = $this->chat->flaggedNoticeFor($conversation);

    expect($notice)->toContain('ALREADY FLAGGED')
        ->and($notice)->toContain('The date of receipt of the demand letter is unconfirmed')
        ->and($notice)->toContain('The lot may be under agrarian reform coverage');
});

it('ignores advisories raised on other conversations', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create();
    $other = Conversation::factory()->for($user)->create();

    Advisory::factory()->for($other)->create(['title' => 'Belongs to another thread']);

    expect($this->chat->flaggedNoticeFor($conversation))->toBeNull();
});

it('attaches advisories filed mid-stream to the message that raised them', function () {
    $conversation = Conversation::factory()->for(User::factory())->create();

    // Filed by the tool during the stream, before the assistant message exists.
    $fresh = Advisory::factory()->for($conversation)->create(['message_id' => null]);

    $earlierMessage = Message::factory()->for($conversation)->create(['role' => 'assistant']);
    $earlier = Advisory::factory()->for($conversation)->create(['message_id' => $earlierMessage->id]);

    $assistantMessageId = (string) Str::uuid();
    $this->chat->persistFor($conversation, $assistantMessageId);

    expect($fresh->fresh()->message_id)->toBe($assistantMessageId)
        // An earlier turn's advisory keeps the message it was raised on.
        ->and($earlier->fresh()->message_id)->toBe($earlierMessage->id);
});
