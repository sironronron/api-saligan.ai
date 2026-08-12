<?php

namespace App\Services\Documents;

use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Files\LocalDocument;
use Laravel\Ai\Files\LocalImage;
use Laravel\Ai\Promptable;

class ImageOcrExtractor
{
    /**
     * Whether this extractor can read the given file. Images and PDFs are both
     * handed to the vision model directly — a scanned PDF carries no text
     * layer, so the page images are the only thing to read.
     */
    public static function handles(string $mimeType): bool
    {
        return str_starts_with($mimeType, 'image/') || $mimeType === 'application/pdf';
    }

    /**
     * Transcribe the text contained in a local image or PDF using a
     * vision-capable model.
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
the supplied file verbatim, preserving the original language (English,
Filipino, or mixed) and keeping numbers, dates, names, addresses, and
reference/case numbers exactly as written. Preserve paragraph and line breaks
between distinct blocks, and transcribe every page in order. Do not summarize,
translate, correct, or add any text that is not present in the file. If it
contains no readable text, reply with exactly: NO_TEXT
PROMPT;
            }
        };

        $file = $mimeType === 'application/pdf'
            ? new LocalDocument($fullPath, $mimeType)
            : new LocalImage($fullPath, $mimeType);

        $response = $agent->prompt(
            'Extract every line of text from this document. Reply with only the transcribed text.',
            [$file],
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
                : $this->ollamaFallback($provider),
            // Explicitly chosen, so this is the intended provider rather than
            // a degraded one.
            'ollama' => $this->ollama(),
            default => filled(config('ai.providers.gemini.key'))
                ? [Lab::Gemini, $model ?: config('saligan.chat.gemini_model')]
                : $this->ollamaFallback($provider),
        };
    }

    /**
     * @return array{0: Lab, 1: string}
     */
    /**
     * Ollama as an intentional choice.
     *
     * @return array{0: Lab, 1: string}
     */
    protected function ollama(): array
    {
        return [Lab::Ollama, config('saligan.chat.ollama_model')];
    }

    /**
     * Ollama because the configured provider is unusable.
     *
     * Logged rather than silent: in production this swaps a vision model for a
     * local one, which surfaces as poor OCR quality (or a connection error)
     * with nothing pointing at the missing key that caused it.
     *
     * @return array{0: Lab, 1: string}
     */
    protected function ollamaFallback(string $configured): array
    {
        Log::warning('OCR fell back to Ollama: the configured provider has no API key.', [
            'configured_provider' => $configured,
        ]);

        return $this->ollama();
    }
}
