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

    expect($agent->providerOptions(Lab::Anthropic)['system'])->toBe([
        [
            'type' => 'text',
            'text' => 'You are Saligan, a Philippine legal research assistant.',
            'cache_control' => ['type' => 'ephemeral', 'ttl' => '1h'],
        ],
        [
            'type' => 'text',
            'text' => 'EXPORT INSTRUCTIONS\n...',
        ],
    ]);
});

it('omits the cache breakpoint for Anthropic when context caching is disabled', function () {
    config(['saligan.context_caching.enabled' => false]);

    $agent = new LegalChatAgent(
        instructions: 'EXPORT INSTRUCTIONS\n...',
        staticInstructions: 'You are Saligan, a Philippine legal research assistant.',
    );

    expect($agent->providerOptions(Lab::Anthropic)['system'])->toBe([
        [
            'type' => 'text',
            'text' => 'You are Saligan, a Philippine legal research assistant.',
        ],
        [
            'type' => 'text',
            'text' => 'EXPORT INSTRUCTIONS\n...',
        ],
    ]);
});

it('emits only the static block for Anthropic when there are no dynamic instructions', function () {
    $agent = new LegalChatAgent(staticInstructions: 'You are Saligan, a Philippine legal research assistant.');

    expect($agent->providerOptions(Lab::Anthropic)['system'])->toBe([
        [
            'type' => 'text',
            'text' => 'You are Saligan, a Philippine legal research assistant.',
            'cache_control' => ['type' => 'ephemeral', 'ttl' => '1h'],
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

    expect($agent->providerOptions(Lab::Ollama))
        ->toBe(['think' => false, 'num_ctx' => config('saligan.chat.ollama_num_ctx')])
        ->and($agent->providerOptions(Lab::OpenAI))->toBe([])
        ->and($agent->providerOptions(Lab::Gemini))->toBe([]);
});

/**
 * Ollama defaults num_ctx to 4096 and truncates a longer prompt to its tail
 * without saying so. A drafting turn sends ~23k tokens, so at the default the
 * persona, the drafting rules and the user's uploaded template were all cut
 * away before the model saw them and no document was ever produced. The value
 * has to travel with every Ollama request for that not to come back.
 */
it('sends an explicit context window to Ollama so the prompt is not truncated', function () {
    config(['saligan.chat.ollama_num_ctx' => 16384]);

    $agent = new LegalChatAgent(instructions: 'dynamic instructions');

    expect($agent->providerOptions(Lab::Ollama)['num_ctx'])->toBe(16384);
});

/**
 * laravel/ai falls back to 60s, which Guzzle applies as an idle timeout on the
 * streamed body. Local inference is silent for minutes while it reads a
 * drafting prompt, so the default aborted the turn before the first token.
 */
it('overrides the library default chat timeout', function () {
    config(['saligan.chat.timeout' => 240]);

    expect((new LegalChatAgent)->timeout())->toBe(240)
        ->and((new LegalChatAgent)->timeout())->toBeGreaterThan(60);
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

it('sends the configured effort so requests are not silently run at the default', function () {
    // Sonnet 5 defaults to `high`. Nothing set it, so every answer paid for the
    // most deliberate setting — the single largest latency lever on the call.
    config(['saligan.chat.effort' => 'medium']);

    $agent = new LegalChatAgent(staticInstructions: 'static');

    expect($agent->providerOptions(Lab::Anthropic)['output_config'])
        ->toBe(['effort' => 'medium']);
});

it('omits effort entirely when it is unset, deferring to the model default', function () {
    config(['saligan.chat.effort' => null]);

    $agent = new LegalChatAgent(staticInstructions: 'static');

    expect($agent->providerOptions(Lab::Anthropic))->not->toHaveKey('output_config');
});
