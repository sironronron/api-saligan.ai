<?php

namespace App\Services\TextRewrite;

use App\Ai\TextRewriteAgent;
use App\Enums\ChatProvider;
use App\Models\Conversation;
use App\Services\MatterMemory\MatterMemoryService;
use App\Support\CaseContextBlock;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Enums\Lab;

/**
 * Rewrites a selected passage of a letter through the AI agent and returns
 * plain text. The model's reply is treated as untrusted: JSON wrappers (the
 * local Ollama models are asked for valid JSON) are unwrapped, and an empty
 * answer triggers a retry rather than shipping nothing back to the editor.
 */
class TextRewriteService
{
    /**
     * Rewrite the passage.
     *
     * Returns null when every attempt comes back with nothing usable. It used
     * to hand the original text back instead, which reaches the editor as a
     * suggestion identical to what is already there — indistinguishable, to
     * the reader, from a rewrite that decided no change was needed.
     *
     * @param  Conversation|null  $conversation  The thread this letter belongs
     *                                           to, for the matter's own facts.
     */
    public function rewrite(string $text, string $instruction, ?Conversation $conversation = null): ?string
    {
        [$provider, $model] = $this->resolveProvider();

        $agent = new TextRewriteAgent(
            text: $text,
            instruction: $instruction,
            context: $this->contextFor($conversation),
        );

        $raw = '';

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            if ($attempt > 1) {
                Log::warning('Text rewrite returned an empty reply; retrying.', [
                    'provider' => $provider instanceof Lab ? $provider->value : (string) $provider,
                    'model' => $model,
                    'attempt' => $attempt,
                ]);
            }

            $raw = (string) $agent->prompt($text, [], $provider, $model)->text;
            $rewritten = $this->extractText($raw);

            if ($rewritten !== '') {
                return $rewritten;
            }
        }

        Log::warning('Text rewrite gave up after three empty replies.', [
            'provider' => $provider instanceof Lab ? $provider->value : (string) $provider,
            'model' => $model,
            'last_reply' => Str::limit($raw, 200),
        ]);

        return null;
    }

    /**
     * The matter this passage belongs to, as reference material.
     *
     * The rewrite used to be entirely stateless, so "fix the grammar of this
     * clause" was answered by a model that had never seen the case — it could
     * not tell a party's name from a typo, and had no way to keep a rewritten
     * sentence consistent with the rest of the matter. The block is reference
     * only: the agent is told in the strongest terms that it may not import a
     * fact from here into the passage.
     */
    protected function contextFor(?Conversation $conversation): string
    {
        if ($conversation === null) {
            return '';
        }

        $case = $conversation->case;
        $blocks = [];

        if ($case !== null) {
            $blocks[] = 'CASE
'.trim(app(CaseContextBlock::class)->for($case));
            $blocks[] = 'MATTER MEMORY
'.trim(app(MatterMemoryService::class)->getMemoryBlock($case));
        }

        // The last few turns, so a rewrite lands in the register the rest of
        // the conversation established. Trimmed hard — this is a rewrite of one
        // passage, not a new answer, and the passage itself is the subject.
        $recent = $conversation->messages()
            ->latest()
            ->limit(6)
            ->get()
            ->reverse()
            ->map(fn ($message): string => Str::limit(
                mb_strtoupper($message->role->value).': '.trim((string) $message->content),
                400,
            ))
            ->filter()
            ->implode("\n");

        if (trim($recent) !== '') {
            $blocks[] = 'RECENT CONVERSATION
'.$recent;
        }

        return implode("\n\n", $blocks);
    }

    /**
     * Pull the actual text out of the model's reply. Ollama is asked for valid
     * JSON, which it may return as a bare JSON string ("...") or an object
     * like {"text": "..."}; cloud providers usually return the plain text
     * directly. Any of those shapes is accepted, and stray wrapper quotes are
     * stripped.
     */
    protected function extractText(string $raw): string
    {
        $trimmed = trim($raw);

        if ($trimmed === '') {
            return '';
        }

        $decoded = json_decode($trimmed, true);

        if (is_string($decoded)) {
            return trim($decoded);
        }

        if (is_array($decoded)) {
            if (isset($decoded['text']) && is_string($decoded['text'])) {
                return trim($decoded['text']);
            }

            foreach ($decoded as $value) {
                if (is_string($value) && trim($value) !== '') {
                    return trim($value);
                }
            }

            // A JSON structure carrying no usable string is not a rewrite. It
            // used to fall through to the return below, which handed the raw
            // source back — so a model answering `{}` put a literal empty
            // object into the letter, and because that string is non-empty the
            // retry loop accepted it as a successful rewrite.
            return '';
        }

        // A bare JSON scalar that is not a string — `null`, a number, `true` —
        // is the same kind of non-answer.
        if ($decoded !== null && ! is_array($decoded)) {
            return '';
        }

        if ($trimmed === 'null') {
            return '';
        }

        // Plain text, possibly wrapped in quotes or a code fence.
        $trimmed = (string) preg_replace('/^```[a-z]*\s*\n?|\n?```$/i', '', $trimmed);
        $trimmed = trim($trimmed);

        if (strlen($trimmed) >= 2 && $trimmed[0] === '"' && $trimmed[-1] === '"') {
            return trim(substr($trimmed, 1, -1));
        }

        return $trimmed;
    }

    /**
     * The provider and model the rewrite runs on, resolved the same way the
     * letter-draft and chat paths resolve them: the configured provider when
     * its key is present, otherwise a local Ollama model.
     *
     * @return array{0: Lab|string, 1: string}
     */
    protected function resolveProvider(): array
    {
        return match (ChatProvider::fromConfig()) {
            ChatProvider::Anthropic => filled(config('ai.providers.anthropic.key'))
                ? [Lab::Anthropic, (string) config('saligan.chat.anthropic_model')]
                : $this->ollamaFallback('anthropic'),
            ChatProvider::Gemini => filled(config('ai.providers.gemini.key'))
                ? [Lab::Gemini, (string) config('saligan.chat.gemini_model')]
                : $this->ollamaFallback('gemini'),
            ChatProvider::OpenAI => filled(config('ai.providers.openai.key'))
                ? [Lab::OpenAI, (string) config('saligan.chat.openai_model')]
                : $this->ollamaFallback('openai'),
            ChatProvider::Meta => filled(config('ai.providers.meta.key'))
                ? ['meta', (string) config('saligan.chat.meta_model')]
                : $this->ollamaFallback('meta'),
            default => $this->ollamaFallback('ollama'),
        };
    }

    /**
     * @return array{0: Lab|string, 1: string}
     */
    protected function ollamaFallback(string $configured): array
    {
        if ($configured !== 'ollama') {
            Log::warning('Text rewrite fell back to Ollama: the configured provider has no API key.', [
                'configured_provider' => $configured,
            ]);
        }

        return [Lab::Ollama, (string) config('saligan.chat.ollama_model')];
    }
}
