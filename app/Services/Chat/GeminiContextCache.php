<?php

namespace App\Services\Chat;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Manages a Gemini CachedContent for the static Saligan system prompt.
 *
 * The cache holds the persona plus the standing instruction blocks so every
 * chat turn bills those tokens at the reduced cached-input rate. The cached
 * content is created once and reused across turns; it expires after the TTL,
 * at which point the next request recreates it.
 *
 * @see https://ai.google.dev/gemini-api/docs/caching
 */
class GeminiContextCache
{
    /**
     * The resource name of the CachedContent for the given static system
     * prompt, or null when caching is disabled, not configured, or creation
     * fails (the caller then proceeds without cached input pricing).
     */
    public function nameFor(string $model, string $systemInstruction): ?string
    {
        if (! config('saligan.context_caching.enabled')) {
            return null;
        }

        if ($this->apiKey() === '') {
            return null;
        }

        $key = $this->cacheKey($model, $systemInstruction);
        $entry = Cache::get($key);

        if (is_array($entry) && filled($entry['name'] ?? null) && ($entry['expires_at'] ?? 0) > now()->timestamp) {
            return $entry['name'];
        }

        try {
            $response = Http::baseUrl($this->baseUrl())
                ->withHeaders(['x-goog-api-key' => $this->apiKey()])
                ->acceptJson()
                ->asJson()
                ->timeout((int) config('saligan.context_caching.create_timeout', 10))
                ->post('/cachedContents', [
                    'model' => 'models/'.$model,
                    'displayName' => 'saligan-system-prompt',
                    'systemInstruction' => ['parts' => [['text' => $systemInstruction]]],
                    'ttl' => config('saligan.context_caching.ttl_seconds').'s',
                ]);

            $name = $response->throw()->json('name');

            Cache::put($key, [
                'name' => $name,
                'expires_at' => now()->addSeconds((int) config('saligan.context_caching.ttl_seconds'))->timestamp,
            ], (int) config('saligan.context_caching.refresh_seconds'));

            Log::info('Gemini context cache created', ['name' => $name, 'model' => $model]);

            return $name;
        } catch (\Throwable $e) {
            Log::warning('Could not create Gemini context cache: '.$e->getMessage());

            return null;
        }
    }

    protected function baseUrl(): string
    {
        return rtrim((string) config('ai.providers.gemini.url', 'https://generativelanguage.googleapis.com/v1beta/'), '/');
    }

    protected function apiKey(): string
    {
        return (string) config('ai.providers.gemini.key');
    }

    protected function cacheKey(string $model, string $systemInstruction): string
    {
        return 'gemini-context-cache:'.md5($model.'|'.$systemInstruction);
    }
}
