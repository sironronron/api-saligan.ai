<?php

use App\Enums\ChatProvider;
use App\Models\Conversation;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Chat\ChatService;
use Laravel\Ai\Enums\Lab;

beforeEach(function () {
    $this->chat = new class extends ChatService
    {
        public function __construct() {}

        public function resolveFor(Conversation $conversation): array
        {
            return $this->resolveProvider($conversation);
        }
    };
});

it('derives the default provider from configuration', function () {
    config()->set('saligan.chat.provider', 'gemini');
    expect(ChatProvider::fromConfig())->toBe(ChatProvider::Gemini);

    config()->set('saligan.chat.provider', 'unknown');
    expect(ChatProvider::fromConfig())->toBe(ChatProvider::Ollama);
});

it('resolves Ollama for conversations stored as Ollama', function () {
    $conversation = Conversation::factory()->create([
        'provider' => ChatProvider::Ollama,
    ]);

    expect($this->chat->resolveFor($conversation))->toBe([
        Lab::Ollama,
        config('saligan.chat.ollama_model'),
    ]);
});

it('falls back to Ollama when Gemini is stored but no API key is configured', function () {
    config()->set('ai.providers.gemini.key', '');

    $conversation = Conversation::factory()->create([
        'provider' => ChatProvider::Gemini,
    ]);

    expect($this->chat->resolveFor($conversation))->toBe([
        Lab::Ollama,
        config('saligan.chat.ollama_model'),
    ]);
});

it('uses Gemini when Gemini is stored and an API key is configured', function () {
    config()->set('ai.providers.gemini.key', 'test-key');

    $conversation = Conversation::factory()->create([
        'provider' => ChatProvider::Gemini,
    ]);

    expect($this->chat->resolveFor($conversation))->toBe([
        Lab::Gemini,
        config('saligan.chat.gemini_model'),
    ]);
});

it('falls back to Ollama when OpenAI is stored but no API key is configured', function () {
    config()->set('ai.providers.openai.key', '');

    $conversation = Conversation::factory()->create([
        'provider' => ChatProvider::OpenAI,
    ]);

    expect($this->chat->resolveFor($conversation))->toBe([
        Lab::Ollama,
        config('saligan.chat.ollama_model'),
    ]);
});

it('uses OpenAI when OpenAI is stored and an API key is configured', function () {
    config()->set('ai.providers.openai.key', 'test-key');

    $conversation = Conversation::factory()->create([
        'provider' => ChatProvider::OpenAI,
    ]);

    expect($this->chat->resolveFor($conversation))->toBe([
        Lab::OpenAI,
        config('saligan.chat.openai_model'),
    ]);
});

it('falls back to Gemini when Anthropic is stored but no API key is configured', function () {
    config()->set('ai.providers.anthropic.key', '');

    $conversation = Conversation::factory()->create([
        'provider' => ChatProvider::Anthropic,
    ]);

    expect($this->chat->resolveFor($conversation))->toBe([
        Lab::Gemini,
        config('saligan.chat.gemini_model'),
    ]);
});

it('uses Anthropic when Anthropic is stored and an API key is configured', function () {
    config()->set('ai.providers.anthropic.key', 'test-key');

    $conversation = Conversation::factory()->create([
        'provider' => ChatProvider::Anthropic,
    ]);

    expect($this->chat->resolveFor($conversation))->toBe([
        Lab::Anthropic,
        config('saligan.chat.anthropic_model'),
    ]);
});

it('derives Anthropic as the default provider from configuration', function () {
    config()->set('saligan.chat.provider', 'anthropic');
    expect(ChatProvider::fromConfig())->toBe(ChatProvider::Anthropic);
});

/**
 * An Anthropic conversation owned by a user whose organization is in the given
 * subscription state.
 */
function anthropicConversationFor(array $subscription): Conversation
{
    $organization = Organization::factory()->create();

    Subscription::factory()->create([...$subscription, 'organization_id' => $organization->id]);

    return Conversation::factory()->create([
        'provider' => ChatProvider::Anthropic,
        'user_id' => User::factory()->memberOf($organization),
    ]);
}

/*
 * A trial earns nothing and its message allowance is generous, so it is served
 * the cheaper model. What is being switched is cost, not access: both models
 * answer from the same retrieved sources.
 */
it('serves the trial model to an organization still inside its trial', function () {
    config()->set('ai.providers.anthropic.key', 'test-key');

    $conversation = anthropicConversationFor([
        'status' => Subscription::STATUS_TRIALING,
        'trial_ends_at' => now()->addDays(7),
    ]);

    expect($this->chat->resolveFor($conversation))->toBe([
        Lab::Anthropic,
        config('saligan.chat.anthropic_trial_model'),
    ]);
});

it('serves the paid model to a paying organization', function () {
    config()->set('ai.providers.anthropic.key', 'test-key');

    $conversation = anthropicConversationFor(['status' => Subscription::STATUS_ACTIVE]);

    expect($this->chat->resolveFor($conversation))->toBe([
        Lab::Anthropic,
        config('saligan.chat.anthropic_model'),
    ]);
});

/*
 * A lapsed trial is not a trial. Such a user has no access at all and never
 * reaches a chat turn, but the model choice must not quietly treat the expired
 * row as one that still earns the discount.
 */
it('serves the paid model once the trial has lapsed', function () {
    config()->set('ai.providers.anthropic.key', 'test-key');

    $conversation = anthropicConversationFor([
        'status' => Subscription::STATUS_TRIALING,
        'trial_ends_at' => now()->subDay(),
    ]);

    expect($this->chat->resolveFor($conversation))->toBe([
        Lab::Anthropic,
        config('saligan.chat.anthropic_model'),
    ]);
});

it('serves the paid model to everyone when no trial model is configured', function () {
    config()->set('ai.providers.anthropic.key', 'test-key');
    config()->set('saligan.chat.anthropic_trial_model', null);

    $conversation = anthropicConversationFor([
        'status' => Subscription::STATUS_TRIALING,
        'trial_ends_at' => now()->addDays(7),
    ]);

    expect($this->chat->resolveFor($conversation))->toBe([
        Lab::Anthropic,
        config('saligan.chat.anthropic_model'),
    ]);
});
