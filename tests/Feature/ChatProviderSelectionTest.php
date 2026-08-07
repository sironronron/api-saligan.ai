<?php

use App\Enums\ChatProvider;
use App\Models\Conversation;
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
