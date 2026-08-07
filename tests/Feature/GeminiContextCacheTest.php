<?php

use App\Ai\LegalChatAgent;
use App\Services\Chat\GeminiContextCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Enums\Lab;

beforeEach(function () {
    config(['saligan.context_caching.enabled' => true]);
    config(['saligan.context_caching.ttl_seconds' => 3600]);
    config(['saligan.context_caching.refresh_seconds' => 3000]);
    config(['ai.providers.gemini.key' => 'gemini-key']);
    config(['ai.providers.gemini.url' => 'https://generativelanguage.googleapis.com/v1beta/']);
    Cache::flush();
});

it('creates a cached content and returns its resource name', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'name' => 'cachedContents/abc123',
            'model' => 'models/gemini-3.6-flash',
        ]),
    ]);

    $name = app(GeminiContextCache::class)->nameFor('gemini-3.6-flash', 'STATIC SYSTEM PROMPT');

    expect($name)->toBe('cachedContents/abc123');

    Http::assertSent(function ($request) {
        $body = $request->data();

        return $request->url() === 'https://generativelanguage.googleapis.com/v1beta/cachedContents'
            && $request->hasHeader('x-goog-api-key', 'gemini-key')
            && data_get($body, 'model') === 'models/gemini-3.6-flash'
            && data_get($body, 'systemInstruction.parts.0.text') === 'STATIC SYSTEM PROMPT'
            && data_get($body, 'ttl') === '3600s';
    });
});

it('reuses the cached content name without hitting the API again', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'name' => 'cachedContents/abc123',
        ]),
    ]);

    $cache = app(GeminiContextCache::class);

    $cache->nameFor('gemini-3.6-flash', 'STATIC SYSTEM PROMPT');
    $cache->nameFor('gemini-3.6-flash', 'STATIC SYSTEM PROMPT');

    Http::assertSentCount(1);
});

it('uses a distinct cache per model and system prompt', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'name' => 'cachedContents/abc123',
        ]),
    ]);

    $cache = app(GeminiContextCache::class);

    $cache->nameFor('gemini-3.6-flash', 'PROMPT A');
    $cache->nameFor('gemini-3.6-flash', 'PROMPT B');
    $cache->nameFor('gemini-3.6-flash', 'PROMPT A');

    Http::assertSentCount(2);
});

it('returns null when context caching is disabled', function () {
    config(['saligan.context_caching.enabled' => false]);

    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['name' => 'cachedContents/x'])]);

    $name = app(GeminiContextCache::class)->nameFor('gemini-3.6-flash', 'STATIC SYSTEM PROMPT');

    expect($name)->toBeNull();

    Http::assertNothingSent();
});

it('returns null and does not throw when creation fails', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'error' => ['message' => 'boom'],
        ], 500),
    ]);

    $name = app(GeminiContextCache::class)->nameFor('gemini-3.6-flash', 'STATIC SYSTEM PROMPT');

    expect($name)->toBeNull();
});

it('passes the cached content name as a Gemini provider option', function () {
    $agent = new LegalChatAgent(cachedContent: 'cachedContents/abc123');

    expect($agent->providerOptions(Lab::Gemini))
        ->toBe(['cachedContent' => 'cachedContents/abc123']);
});

it('does not pass the cached content for other providers', function () {
    $agent = new LegalChatAgent(cachedContent: 'cachedContents/abc123');

    expect($agent->providerOptions(Lab::Ollama))->toBe(['think' => false])
        ->and($agent->providerOptions(Lab::OpenAI))->toBe([]);
});

it('returns no cached content option when none is set', function () {
    $agent = new LegalChatAgent;

    expect($agent->providerOptions(Lab::Gemini))->toBe([]);
});
