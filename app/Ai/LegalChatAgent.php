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
     */
    public function __construct(
        public string $instructions = '',
        public iterable $messages = [],
        public array $tools = [],
        public ?string $cachedContent = null,
    ) {
        //
    }

    public function instructions(): string
    {
        return $this->instructions;
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
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        if ($provider === Lab::Ollama || $provider === 'ollama') {
            return ['think' => false];
        }

        if (($provider === Lab::Gemini || $provider === 'gemini') && $this->cachedContent !== null) {
            return ['cachedContent' => $this->cachedContent];
        }

        return [];
    }
}
