<?php

namespace App\Services\Documents;

use App\Ai\DocumentCategoryAgent;
use App\Enums\LabelKind;
use App\Models\Document;
use App\Models\Label;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

/**
 * Files a freshly ingested document under the case-file categories it belongs
 * to, so a lawyer opens a case with the material already sorted instead of
 * facing a flat list of uploads.
 */
class DocumentClassifier
{
    /**
     * Suggest and apply categories for a document.
     *
     * Never throws: a document that cannot be classified is still a perfectly
     * good document, and the ingestion that produced it must not fail over a
     * filing suggestion. An unclassified document surfaces in the Unfiled
     * queue, which is the same place a low-confidence answer leaves it.
     */
    public function classify(Document $document, string $text): void
    {
        if (! config('saligan.documents.classification.enabled', true)) {
            return;
        }

        try {
            if ($this->wasFiledByHand($document)) {
                return;
            }

            $vocabulary = $this->vocabularyFor($document);

            if ($vocabulary->isEmpty()) {
                return;
            }

            $suggestions = $this->suggest($document, $text, $vocabulary);

            if ($suggestions === []) {
                return;
            }

            $document->syncSuggestedLabels($suggestions);
        } catch (Throwable $exception) {
            Log::warning('Document classification failed; the document was left unfiled.', [
                'document_id' => $document->id,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Ask the model which categories the document belongs to, and keep only
     * the answers confident enough to act on.
     *
     * @param  Collection<int, Label>  $vocabulary
     * @return array<int, array{label: Label, confidence: float}>
     */
    public function suggest(Document $document, string $text, Collection $vocabulary): array
    {
        [$provider, $model] = $this->resolveProvider();

        $agent = new DocumentCategoryAgent(
            slugs: $vocabulary->pluck('slug')->all(),
            vocabulary: $this->renderVocabulary($vocabulary),
        );

        $response = $agent->prompt($this->buildPrompt($document, $text), [], $provider, $model);

        return $this->selectConfident($this->readCandidates($response), $vocabulary);
    }

    /**
     * The categories this document's owner may file under: the seeded system
     * vocabulary plus whatever their firm has added.
     *
     * @return Collection<int, Label>
     */
    protected function vocabularyFor(Document $document): Collection
    {
        $owner = $document->user;

        if ($owner === null) {
            return new Collection;
        }

        return Label::query()
            ->visibleTo($owner)
            ->active()
            ->where('kind', LabelKind::DocumentCategory)
            ->orderBy('position')
            ->get();
    }

    /**
     * Whether a person has already filed this document. Their filing is the
     * answer, and no later pass may overwrite it.
     */
    protected function wasFiledByHand(Document $document): bool
    {
        return $document->labels()->wherePivot('source', 'user')->exists();
    }

    /**
     * Render the vocabulary as the lines the model chooses between.
     *
     * @param  Collection<int, Label>  $vocabulary
     */
    protected function renderVocabulary(Collection $vocabulary): string
    {
        return $vocabulary
            ->map(fn (Label $label): string => trim(sprintf(
                '- %s (%s): %s',
                $label->slug,
                $label->name,
                $label->description ?? 'No description given.',
            )))
            ->implode("\n");
    }

    /**
     * The excerpt handed to the model: the filename, the title the uploader
     * gave it, and the opening of the extracted text. The opening is where
     * captions, letterheads, and titles live, which is what actually decides
     * the filing.
     */
    protected function buildPrompt(Document $document, string $text): string
    {
        $excerpt = Str::limit(
            trim($text),
            (int) config('saligan.documents.classification.excerpt_characters', 6000),
            '…',
        );

        return <<<PROMPT
Filename: {$document->original_filename}
Title given by the uploader: {$document->title}

Opening of the document:
---
{$excerpt}
---

Which case-file categories does this document belong to?
PROMPT;
    }

    /**
     * Read the model's answer defensively. A provider that ignores the schema
     * is a wrong answer, not an exception: anything unparseable becomes an
     * empty list and the document stays unfiled.
     *
     * @return array<int, array{slug: string, confidence: float}>
     */
    protected function readCandidates(mixed $response): array
    {
        if (! $response instanceof StructuredAgentResponse) {
            return [];
        }

        $categories = $response->structured['categories'] ?? [];

        if (! is_array($categories)) {
            return [];
        }

        $candidates = [];

        foreach ($categories as $category) {
            if (! is_array($category) || ! isset($category['slug'])) {
                continue;
            }

            $candidates[] = [
                'slug' => (string) $category['slug'],
                'confidence' => (float) ($category['confidence'] ?? 0.0),
            ];
        }

        return $candidates;
    }

    /**
     * Keep the confident, known, distinct answers — most confident first — and
     * no more of them than a document is allowed to carry.
     *
     * @param  array<int, array{slug: string, confidence: float}>  $candidates
     * @param  Collection<int, Label>  $vocabulary
     * @return array<int, array{label: Label, confidence: float}>
     */
    protected function selectConfident(array $candidates, Collection $vocabulary): array
    {
        $minConfidence = (float) config('saligan.documents.classification.min_confidence', 0.6);

        $limit = min(
            (int) config('saligan.documents.classification.max_categories', 3),
            LabelKind::DocumentCategory->maxPerRecord(),
        );

        $bySlug = $vocabulary->keyBy('slug');

        return (new Collection($candidates))
            ->filter(fn (array $candidate): bool => $candidate['confidence'] >= $minConfidence)
            ->filter(fn (array $candidate): bool => $bySlug->has($candidate['slug']))
            ->sortByDesc('confidence')
            ->unique('slug')
            ->take($limit)
            ->map(fn (array $candidate): array => [
                'label' => $bySlug->get($candidate['slug']),
                'confidence' => $candidate['confidence'],
            ])
            ->values()
            ->all();
    }

    /**
     * Resolve the provider and model used for classification, falling back to
     * Ollama when the configured provider has no API key.
     *
     * Each provider carries its own cheap default: classification reads a few
     * thousand characters and answers with a short list, so it has no business
     * on the same model that does the drafting.
     *
     * @return array{0: Lab, 1: string}
     */
    protected function resolveProvider(): array
    {
        $provider = (string) config('saligan.documents.classification.provider', 'anthropic');
        $model = config('saligan.documents.classification.model');

        return match ($provider) {
            'anthropic' => filled(config('ai.providers.anthropic.key'))
                ? [Lab::Anthropic, $model ?: 'claude-haiku-4-5-20251001']
                : $this->ollamaFallback($provider),
            'gemini' => filled(config('ai.providers.gemini.key'))
                ? [Lab::Gemini, $model ?: config('saligan.chat.gemini_model')]
                : $this->ollamaFallback($provider),
            'openai' => filled(config('ai.providers.openai.key'))
                ? [Lab::OpenAI, $model ?: 'gpt-4o-mini']
                : $this->ollamaFallback($provider),
            // Explicitly chosen, so this is the intended provider rather than
            // a degraded one.
            'ollama' => [Lab::Ollama, $model ?: config('saligan.chat.ollama_model')],
            default => $this->ollamaFallback($provider),
        };
    }

    /**
     * Ollama because the configured provider is unusable. Logged rather than
     * silent: this quietly swaps a hosted classifier for a local one, which
     * shows up as worse filing with nothing pointing at the missing key.
     *
     * @return array{0: Lab, 1: string}
     */
    protected function ollamaFallback(string $configured): array
    {
        Log::warning('Document classification fell back to Ollama: the configured provider has no API key.', [
            'configured_provider' => $configured,
        ]);

        return [Lab::Ollama, config('saligan.chat.ollama_model')];
    }
}
