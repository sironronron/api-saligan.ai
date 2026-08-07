<?php

use App\Ai\LegalChatAgent;
use Laravel\Ai\Enums\Lab;

beforeEach(function () {
    config(['saligan.context_caching.enabled' => true]);
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
                'cache_control' => ['type' => 'ephemeral'],
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
                'cache_control' => ['type' => 'ephemeral'],
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
