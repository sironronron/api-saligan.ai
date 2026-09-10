<?php

use App\Enums\MessageRole;
use App\Models\Advisory;
use App\Models\Conversation;
use App\Models\CrawledPage;
use App\Models\LegalCase;
use App\Models\LegalChunk;
use App\Models\MatterMemory;
use App\Models\Message;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Todo;
use App\Models\User;
use App\Support\UserProfile;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config([
        'saligan.ai_provider.internal_secret' => 'test-internal-secret',
        'saligan.chat.provider' => 'ollama',
    ]);

    $this->user = User::factory()->create();
    $this->conversation = Conversation::factory()->for($this->user)->create();
    // Turn persistence settles spend, so the user needs a funded
    // subscription the way every front-door caller already does.
    Subscription::factory()->for($this->user)->create([
        'plan_id' => Plan::factory()->pro()->create()->id,
    ]);
});

function internalAiPost(string $path, array $payload = []): TestResponse
{
    return test()->withToken('test-internal-secret')->postJson($path, $payload);
}

it('protects every internal route with the shared secret', function () {
    $this->getJson("/internal/conversations/{$this->conversation->id}/context")
        ->assertUnauthorized()
        ->assertJsonPath('message', 'Invalid internal service credential.');
});

it('returns the prompt-building context', function () {
    $response = $this->withToken('test-internal-secret')
        ->getJson("/internal/conversations/{$this->conversation->id}/context")
        ->assertOk()
        ->assertJsonPath('user_id', $this->user->id)
        ->assertJsonPath('provider', 'ollama')
        ->assertJsonPath('messages', []);

    expect($response->getContent())->toContain('"recent_intake_values":{}');
});

it('returns onboarding profile calibration in the prompt-building context', function () {
    $this->user->forceFill([
        'kyc_role' => UserProfile::ROLE_BUSINESS_OWNER,
        'kyc_use_case' => UserProfile::USE_CASE_CLIENT_WORK,
        'kyc_experience_level' => UserProfile::EXP_PROFESSIONAL,
        'kyc_completed_at' => now(),
    ])->save();

    $this->withToken('test-internal-secret')
        ->getJson("/internal/conversations/{$this->conversation->id}/context")
        ->assertOk()
        ->assertJsonPath('user_profile', fn (string $profile): bool => str_contains($profile, 'Role: Business Owner / Entrepreneur')
            && str_contains($profile, 'Primary use: Preparing documents/research for clients')
            && str_contains($profile, 'Experience level: Professional'));
});

it('omits onboarding calibration when the profile was not completed', function () {
    $this->withToken('test-internal-secret')
        ->getJson("/internal/conversations/{$this->conversation->id}/context")
        ->assertOk()
        ->assertJsonPath('user_profile', '');
});

it('persists a completed turn idempotently', function () {
    $messageId = (string) Str::uuid();
    $payload = [
        'message_id' => $messageId,
        'provider' => 'gemini',
        'user' => ['content' => 'What does the law say?', 'attachment_ids' => []],
        'assistant' => ['content' => 'It depends on the governing statute.'],
        'metadata' => [
            'activity' => [['status' => 'composing', 'label' => 'Writing your answer']],
            'legal_chunk_ids' => [(string) Str::uuid()],
            'document_chunk_ids' => [(string) Str::uuid()],
            'web_citations' => [],
        ],
    ];

    internalAiPost("/internal/conversations/{$this->conversation->id}/messages", $payload)
        ->assertOk()
        ->assertJsonPath('idempotent', false)
        ->assertJsonPath('message_id', $messageId);

    internalAiPost("/internal/conversations/{$this->conversation->id}/messages", $payload)
        ->assertOk()
        ->assertJsonPath('idempotent', true);

    expect(Message::where('conversation_id', $this->conversation->id)->count())->toBe(2);
    $assistant = Message::findOrFail($messageId);

    expect($assistant->role)->toBe(MessageRole::Assistant)
        ->and($assistant->provider->value)->toBe('gemini')
        ->and($assistant->metadata['activity'][0]['status'])->toBe('composing');
});

it('maps Meta to a hosted provider the Python service speaks', function () {
    // Python has no Meta client and rejects the provider outright, so the
    // context builder must never hand it `meta` — previously it fell through
    // to Ollama without saying so.
    config([
        'saligan.chat.provider' => 'meta',
        'ai.providers.meta.key' => 'test-meta-key',
        'ai.providers.gemini.key' => 'test-gemini-key',
    ]);

    $this->withToken('test-internal-secret')
        ->getJson("/internal/conversations/{$this->conversation->id}/context")
        ->assertOk()
        ->assertJsonPath('provider', 'gemini')
        ->assertJsonPath('model', config('saligan.chat.gemini_model'));
});

it('persists the turn usage the provider reports', function () {
    $messageId = (string) Str::uuid();

    internalAiPost("/internal/conversations/{$this->conversation->id}/messages", [
        'message_id' => $messageId,
        'provider' => 'gemini',
        'user' => ['content' => 'What does the law say?', 'attachment_ids' => []],
        'assistant' => ['content' => 'It depends on the governing statute.'],
        'metadata' => [
            'activity' => [['status' => 'composing']],
            'usage' => [
                'provider' => 'gemini',
                'model' => 'gemini-3.7-flash',
                'input_tokens' => 1000,
                'output_tokens' => 100,
                'cache_read_tokens' => 800,
                'cache_write_tokens' => 0,
                'searches' => 1,
            ],
        ],
    ])->assertOk();

    $assistant = Message::findOrFail($messageId);

    expect($assistant->metadata['usage']['model'])->toBe('gemini-3.7-flash')
        ->and($assistant->metadata['usage']['input_tokens'])->toBe(1000)
        ->and($assistant->metadata['usage']['searches'])->toBe(1);
});

it('persists standard chunk ids separately from generic callback metadata', function () {
    $page = CrawledPage::factory()->standard()->create();
    $chunk = LegalChunk::factory()->for($page)->create();
    $messageId = (string) Str::uuid();

    internalAiPost("/internal/conversations/{$this->conversation->id}/messages", [
        'message_id' => $messageId,
        'user' => ['content' => 'What does the standard define?', 'attachment_ids' => []],
        'assistant' => ['content' => 'It defines a message format.'],
        'metadata' => [
            'standard_chunk_ids' => [$chunk->id],
            'activity' => [['status' => 'composing']],
        ],
    ])->assertOk();

    $assistant = Message::findOrFail($messageId);

    expect($assistant->cited_standard_chunk_ids)->toBe([$chunk->id])
        ->and($assistant->metadata)->not->toHaveKey('standard_chunk_ids')
        ->and($assistant->metadata['activity'])->toHaveCount(1);
});

it('creates todos and advisories idempotently by tool call id', function () {
    $todoPayload = [
        'tool_call_id' => 'todo-call-1',
        'items' => [['title' => 'File the complaint', 'status' => 'pending']],
    ];
    $advisoryPayload = [
        'tool_call_id' => 'advisory-call-1',
        'items' => [[
            'kind' => 'deadline',
            'title' => 'The filing period may expire this month',
            'detail' => 'Confirm the date of receipt.',
            'severity' => 'high',
        ]],
    ];

    foreach ([1, 2] as $_) {
        internalAiPost("/internal/conversations/{$this->conversation->id}/todos", $todoPayload)
            ->assertOk()
            ->assertJsonPath('accepted', 1);
        internalAiPost("/internal/conversations/{$this->conversation->id}/advisories", $advisoryPayload)
            ->assertOk()
            ->assertJsonPath('accepted', 1);
    }

    expect(Todo::where('conversation_id', $this->conversation->id)->count())->toBe(1)
        ->and(Advisory::where('conversation_id', $this->conversation->id)->count())->toBe(1);
});

it('normalizes letter callback payloads', function () {
    internalAiPost("/internal/conversations/{$this->conversation->id}/letters", [
        'title' => 'Demand Letter',
        'content' => [
            'type' => 'doc',
            'content' => [['type' => 'paragraph', 'content' => []]],
        ],
        'tool_call_id' => 'letter-call-1',
    ])->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('title', 'Demand Letter')
        ->assertJsonPath('content.type', 'doc');
});

it('stores matter memory through the existing domain service', function () {
    $case = LegalCase::factory()->for($this->user)->create();
    $conversation = Conversation::factory()->for($this->user)->create(['case_id' => $case->id]);

    internalAiPost("/internal/conversations/{$conversation->id}/memory", [
        'facts' => ["matter={$case->id} type=fact content: The client received notice on 1 August."],
        'tool_call_id' => 'memory-call-1',
    ])->assertOk()
        ->assertJsonPath('accepted', 1);

    expect(MatterMemory::where('case_id', $case->id)->count())->toBe(1);
});
