<?php

namespace App\Services\Documents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Files\LocalImage;
use Laravel\Ai\Promptable;

class ImageOcrExtractor
{
    /**
     * Transcribe the text contained in a local image using a vision-capable
     * model.
     */
    public function extract(string $fullPath, string $mimeType): string
    {
        [$provider, $model] = $this->resolveProvider();

        $agent = new class implements Agent
        {
            use Promptable;

            /**
             * Get the instructions that the agent should follow.
             */
            public function instructions(): string
            {
                return <<<'PROMPT'
You are an OCR engine for legal documents. Transcribe ALL visible text from
the supplied image verbatim, preserving the original language (English,
Filipino, or mixed) and keeping numbers, dates, names, addresses, and
reference/case numbers exactly as written. Preserve paragraph and line breaks
between distinct blocks. Do not summarize, translate, correct, or add any text
that is not present in the image. If the image contains no readable text,
reply with exactly: NO_TEXT
PROMPT;
            }
        };

        $response = $agent->prompt(
            'Extract every line of text from this image. Reply with only the transcribed text.',
            [new LocalImage($fullPath, $mimeType)],
            $provider,
            $model,
        );

        $text = trim((string) $response->text);

        return $text === 'NO_TEXT' ? '' : $text;
    }

    /**
     * Resolve the OCR provider, gracefully falling back to Ollama when the
     * configured provider's API key is missing.
     *
     * @return array{0: Lab, 1: string}
     */
    protected function resolveProvider(): array
    {
        $provider = config('saligan.documents.ocr.provider', 'gemini');
        $model = config('saligan.documents.ocr.model', config('saligan.chat.gemini_model'));

        return match ($provider) {
            'openai' => filled(config('ai.providers.openai.key'))
                ? [Lab::OpenAI, $model ?: 'gpt-4o']
                : $this->ollamaFallback(),
            'ollama' => $this->ollamaFallback(),
            default => filled(config('ai.providers.gemini.key'))
                ? [Lab::Gemini, $model ?: config('saligan.chat.gemini_model')]
                : $this->ollamaFallback(),
        };
    }

    /**
     * @return array{0: Lab, 1: string}
     */
    protected function ollamaFallback(): array
    {
        return [Lab::Ollama, config('saligan.chat.ollama_model')];
    }
}
