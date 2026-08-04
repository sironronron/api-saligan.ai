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
     */
    public function __construct(
        public string $instructions = '',
        public iterable $messages = [],
        public array $tools = [],
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
     * Disable Ollama thinking mode so the response lands in the content
     * field instead of the thinking field. Only applies when using Ollama.
     *
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        if ($provider === Lab::Ollama || $provider === 'ollama') {
            return ['think' => false];
        }

        return [];
    }
}
