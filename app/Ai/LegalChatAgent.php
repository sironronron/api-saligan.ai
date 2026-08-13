<?php

namespace App\Ai;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\Message as AiMessage;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Tools\ProviderTool;
use Laravel\Ai\Tool;

class LegalChatAgent implements Agent, Conversational, HasProviderOptions, HasTools
{
    use Promptable;

    /**
     * @param  iterable<int, AiMessage>  $messages
     * @param  array<int, Tool|ProviderTool>  $tools
     * @param  string|null  $cachedContent  Gemini CachedContent resource name.
     * @param  string|null  $staticInstructions  The static system-prompt portion, emitted
     *                                           as the cached Anthropic system block.
     */
    public function __construct(
        public string $instructions = '',
        public iterable $messages = [],
        public array $tools = [],
        public ?string $cachedContent = null,
        public ?string $staticInstructions = null,
    ) {
        //
    }

    public function instructions(): string
    {
        return $this->instructions;
    }

    /**
     * Seconds a single generation step may take.
     *
     * Promptable::getTimeout() falls back to 60 when the agent declares none,
     * and Guzzle enforces it as an idle timeout on the streamed body. A local
     * model chewing through a full drafting prompt sends nothing for minutes,
     * so the default killed the request before the first token — reported, via
     * Guzzle's stream handler, as "Connection refused".
     */
    public function timeout(): int
    {
        return (int) config('saligan.chat.timeout', 300);
    }

    public function messages(): iterable
    {
        return $this->messages;
    }

    public function tools(): iterable
    {
        return $this->tools;
    }

    /**
     * Per-provider request options.
     *
     * For Gemini, reference the cached static system prompt so its tokens are
     * billed at the cached-input rate. The cached content is a prefix of the
     * request: the same static instructions text always leads the system
     * instruction, with per-turn instructions appended after it.
     *
     * For Anthropic, the system prompt is split into a static block (persona +
     * standing rules, identical on every request) and a dynamic block
     * (per-turn export, case, template, and retrieved context). When prompt
     * caching is enabled, the cache breakpoint sits on the static block so it
     * is read from cache on every subsequent request.
     *
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        if ($provider === Lab::Ollama || $provider === 'ollama') {
            // num_ctx must be sent explicitly: Ollama's own default is 4096 and
            // it truncates a longer prompt to the tail without reporting it, so
            // the system prompt and the drafting template were being dropped
            // from every request. `think` is hoisted to the request body by the
            // gateway; num_ctx is passed through in `options`, where Ollama
            // expects it.
            return [
                'think' => false,
                'num_ctx' => (int) config('saligan.chat.ollama_num_ctx', 32768),
            ];
        }

        if (($provider === Lab::Gemini || $provider === 'gemini') && $this->cachedContent !== null) {
            return ['cachedContent' => $this->cachedContent];
        }

        if ($provider === Lab::Anthropic || $provider === 'anthropic') {
            return $this->anthropicProviderOptions();
        }

        return [];
    }

    /**
     * Build the Anthropic system prompt as two blocks, with the static
     * instructions marked as a cache breakpoint when prompt caching is
     * enabled.
     *
     * @return array<string, mixed>
     */
    protected function anthropicProviderOptions(): array
    {
        if ($this->staticInstructions === null) {
            return [];
        }

        $static = [
            'type' => 'text',
            'text' => $this->staticInstructions,
        ];

        if (config('saligan.context_caching.enabled')) {
            $static['cache_control'] = ['type' => 'ephemeral', 'ttl' => $this->cacheTtl()];
        }

        $options = [
            'system' => [
                $static,
                ...(filled($this->instructions) ? [['type' => 'text', 'text' => $this->instructions]] : []),
            ],
        ];

        // Effort is the largest latency lever on the request and was never set,
        // so every answer ran at Sonnet 5's `high` default. Retrieval has
        // already found the authorities by this point, so the model's job is to
        // read and write rather than to search — a lower setting reaches the
        // first token sooner without changing what it is working from.
        if ($effort = config('saligan.chat.effort')) {
            $options['output_config'] = ['effort' => $effort];
        }

        return $options;
    }

    /**
     * The cache lifetime for the static prompt block.
     *
     * Anthropic offers exactly two: five minutes, and an hour that costs 2x the
     * input rate to write rather than 1.25x. The hour is the better buy here
     * because a miss is what actually hurts — the block is ~22k tokens, so a
     * write costs roughly twenty times what a read does, and the five-minute
     * window is short enough that ordinary gaps between questions fall outside
     * it. The block is also identical for every user (it carries no per-user
     * text), so one entry stays warm on aggregate traffic rather than needing
     * each lawyer to sustain a burst of their own.
     */
    protected function cacheTtl(): string
    {
        return (int) config('saligan.context_caching.ttl_seconds') >= 3600 ? '1h' : '5m';
    }
}
