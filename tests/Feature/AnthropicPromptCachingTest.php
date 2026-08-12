<?php

use App\Ai\LegalChatAgent;
use Laravel\Ai\Enums\Lab;

beforeEach(function () {
    config([
        'saligan.context_caching.enabled' => true,
        'saligan.context_caching.ttl_seconds' => 3600,
    ]);
});

it('splits the system prompt and caches the static block for Anthropic', function () {
    $agent = new LegalChatAgent(
        instructions: 'EXPORT INSTRUCTIONS\n...',
        staticInstructions: 'You are Saligan, a Philippine legal research assistant.',
    );

    expect($agent->providerOptions(Lab::Anthropic))->toBe([
        'system' => [
            [
                'type' => 'text',
                'text' => 'You are Saligan, a Philippine legal research assistant.',
                'cache_control' => ['type' => 'ephemeral', 'ttl' => '1h'],
            ],
            [
                'type' => 'text',
                'text' => 'EXPORT INSTRUCTIONS\n...',
            ],
        ],
    ]);
});

it('omits the cache breakpoint for Anthropic when context caching is disabled', function () {
    config(['saligan.context_caching.enabled' => false]);

    $agent = new LegalChatAgent(
        instructions: 'EXPORT INSTRUCTIONS\n...',
        staticInstructions: 'You are Saligan, a Philippine legal research assistant.',
    );

    expect($agent->providerOptions(Lab::Anthropic))->toBe([
        'system' => [
            [
                'type' => 'text',
                'text' => 'You are Saligan, a Philippine legal research assistant.',
            ],
            [
                'type' => 'text',
                'text' => 'EXPORT INSTRUCTIONS\n...',
            ],
        ],
    ]);
});

it('emits only the static block for Anthropic when there are no dynamic instructions', function () {
    $agent = new LegalChatAgent(staticInstructions: 'You are Saligan, a Philippine legal research assistant.');

    expect($agent->providerOptions(Lab::Anthropic))->toBe([
        'system' => [
            [
                'type' => 'text',
                'text' => 'You are Saligan, a Philippine legal research assistant.',
                'cache_control' => ['type' => 'ephemeral', 'ttl' => '1h'],
            ],
        ],
    ]);
});

it('returns no options for Anthropic when no static instructions are provided', function () {
    $agent = new LegalChatAgent(instructions: 'You are Saligan, a Philippine legal research assistant.');

    expect($agent->providerOptions(Lab::Anthropic))->toBe([]);
});

it('does not split the system prompt for other providers', function () {
    $agent = new LegalChatAgent(
        instructions: 'dynamic instructions',
        staticInstructions: 'You are Saligan, a Philippine legal research assistant.',
    );

    expect($agent->providerOptions(Lab::Ollama))->toBe(['think' => false])
        ->and($agent->providerOptions(Lab::OpenAI))->toBe([])
        ->and($agent->providerOptions(Lab::Gemini))->toBe([]);
});

it('uses the hour-long cache when the configured TTL allows it', function () {
    // The static block is ~22k tokens, so a write costs about twenty times a
    // read. The hour window is what keeps ordinary gaps between a lawyer's
    // questions on the read side of that.
    config(['saligan.context_caching.ttl_seconds' => 3600]);

    $agent = new LegalChatAgent(staticInstructions: 'static');

    expect($agent->providerOptions(Lab::Anthropic)['system'][0]['cache_control'])
        ->toBe(['type' => 'ephemeral', 'ttl' => '1h']);
});

it('falls back to the five-minute cache for a shorter configured TTL', function () {
    config(['saligan.context_caching.ttl_seconds' => 300]);

    $agent = new LegalChatAgent(staticInstructions: 'static');

    expect($agent->providerOptions(Lab::Anthropic)['system'][0]['cache_control'])
        ->toBe(['type' => 'ephemeral', 'ttl' => '5m']);
});
