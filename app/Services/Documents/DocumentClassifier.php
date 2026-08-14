<?php

namespace App\Services\Documents;

use App\Ai\DocumentCategoryAgent;
use App\Enums\LabelKind;
use App\Models\Document;
use App\Models\DocumentClassificationRequest;
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

            // Batched: the document is queued and filed by a later sweep,
            // which costs half as much and arrives within the hour. Nothing
            // downstream waits on the filing, so ingestion carries on.
            if ($this->batches()) {
                $this->enqueue($document, $text);

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

        $agent = $this->agentFor($vocabulary);

        $response = $agent->prompt($this->buildPrompt($document, $text), [], $provider, $model);

        return $this->selectConfident($this->readCandidates($response), $vocabulary);
    }

    /**
     * Queue a document for the next classification batch, replacing any
     * request already queued for it — a re-ingested document is classified
     * against the text it has now, not the text it had on the first attempt.
     */
    public function enqueue(Document $document, string $text): DocumentClassificationRequest
    {
        return DocumentClassificationRequest::updateOrCreate(
            ['document_id' => $document->id],
            [
                'prompt' => $this->buildPrompt($document, $text),
                'status' => DocumentClassificationRequest::STATUS_PENDING,
                'batch_id' => null,
                'error' => null,
                'submitted_at' => null,
                'completed_at' => null,
            ],
        );
    }

    /**
     * File a document from an answer that arrived later.
     *
     * The eligibility checks run again here rather than being trusted from
     * submission time: a batch takes up to a day, and in that time somebody
     * may have filed the document by hand — their filing wins — or the firm's
     * categories may have changed underneath it.
     *
     * @param  array<int, array{slug: string, confidence: float}>  $candidates
     */
    public function apply(Document $document, array $candidates): void
    {
        if ($this->wasFiledByHand($document)) {
            return;
        }

        $vocabulary = $this->vocabularyFor($document);

        if ($vocabulary->isEmpty()) {
            return;
        }

        $suggestions = $this->selectConfident($candidates, $vocabulary);

        if ($suggestions === []) {
            return;
        }

        $document->syncSuggestedLabels($suggestions);
    }

    /**
     * The providers that offer an asynchronous batch API, and so can classify
     * in batches rather than inline.
     *
     * @var array<int, Lab>
     */
    protected const BATCH_PROVIDERS = [Lab::Anthropic, Lab::Gemini];

    /**
     * Whether classification should be batched rather than answered inline.
     *
     * A deployment pointed at a provider with no batch API — or at one with no
     * key, which silently degrades to a local model — keeps classifying inline
     * no matter what the flag says.
     */
    public function batches(): bool
    {
        if (! config('saligan.documents.classification.batch.enabled', false)) {
            return false;
        }

        return $this->batchProvider() !== null;
    }

    /**
     * The provider batched classification runs on, or null when this
     * deployment is not classifying on one that batches.
     */
    public function batchProvider(): ?Lab
    {
        $provider = $this->resolveProvider()[0];

        return in_array($provider, self::BATCH_PROVIDERS, true) ? $provider : null;
    }

    /**
     * The model batched classification runs on, or null when this deployment
     * is not classifying on a provider that batches.
     */
    public function batchModel(): ?string
    {
        [$provider, $model] = $this->resolveProvider();

        return in_array($provider, self::BATCH_PROVIDERS, true) ? $model : null;
    }

    /**
     * The agent whose instructions and output shape define a classification,
     * batched or not.
     *
     * @param  Collection<int, Label>  $vocabulary
     */
    public function agentFor(Collection $vocabulary): DocumentCategoryAgent
    {
        return new DocumentCategoryAgent(
            slugs: $vocabulary->pluck('slug')->all(),
            vocabulary: $this->renderVocabulary($vocabulary),
        );
    }

    /**
     * Read the categories out of a raw JSON answer, as a batch result carries
     * it. Unparseable JSON is a wrong answer, not an exception — the document
     * stays unfiled, exactly as it would on a malformed inline response.
     *
     * @return array<int, array{slug: string, confidence: float}>
     */
    public function readJsonCandidates(string $json): array
    {
        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            return [];
        }

        return $this->candidatesFrom($decoded['categories'] ?? []);
    }

    /**
     * The categories this document's owner may file under: the seeded system
     * vocabulary plus whatever their firm has added.
     *
     * @return Collection<int, Label>
     */
    public function vocabularyFor(Document $document): Collection
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

        return $this->candidatesFrom($response->structured['categories'] ?? []);
    }

    /**
     * Normalise the model's category list, whichever shape it arrived in.
     *
     * @return array<int, array{slug: string, confidence: float}>
     */
    protected function candidatesFrom(mixed $categories): array
    {
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
