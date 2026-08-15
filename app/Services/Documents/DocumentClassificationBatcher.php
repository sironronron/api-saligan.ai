<?php

namespace App\Services\Documents;

use App\Models\DocumentClassificationRequest;
use App\Services\Ai\BatchClient;
use App\Services\Ai\BatchClientFactory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Drives batched document classification: gathers the documents waiting to be
 * filed into one batch, and files them when the answers land.
 *
 * The two halves run on their own schedules — submitting is cheap and can wait
 * for a worthwhile batch to accumulate, while collecting only needs to keep up
 * with batches ending.
 *
 * Which provider answers is a deployment choice, and this class does not know:
 * BatchClient normalizes the envelope, the status vocabulary and the result
 * shape, so everything here is about classification rather than about
 * Anthropic or Gemini.
 */
class DocumentClassificationBatcher
{
    public function __construct(
        private readonly DocumentClassifier $classifier,
        private readonly BatchClientFactory $clients,
    ) {
        //
    }

    /**
     * Submit the queued documents as one batch, returning its id — or null
     * when there was nothing worth sending.
     */
    public function submit(?int $limit = null): ?string
    {
        $client = $this->client();
        $model = $this->classifier->batchModel();

        if ($client === null || $model === null) {
            return null;
        }

        $limit ??= (int) config('saligan.documents.classification.batch.max_requests', 500);

        $queued = DocumentClassificationRequest::query()
            ->pending()
            ->with('document.user')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($queued->isEmpty()) {
            return null;
        }

        [$requests, $submitting] = $this->buildRequests($queued);

        if ($requests === []) {
            return null;
        }

        try {
            $batchId = $client->create(
                $requests,
                $model,
                (int) config('saligan.documents.classification.batch.max_tokens', 1024),
                'document-classification',
            );
        } catch (Throwable $exception) {
            // Left pending on purpose: a failed submission is a transport
            // problem, and the next sweep should try these documents again
            // rather than abandoning them unfiled.
            Log::warning('Document classification batch could not be submitted.', [
                'requests' => count($requests),
                'exception' => $exception->getMessage(),
                'response' => $this->responseBody($exception),
            ]);

            return null;
        }

        DocumentClassificationRequest::query()
            ->whereIn('id', $submitting)
            ->update([
                'status' => DocumentClassificationRequest::STATUS_SUBMITTED,
                'batch_id' => $batchId,
                'submitted_at' => now(),
                'updated_at' => now(),
            ]);

        return $batchId;
    }

    /**
     * Poll every open batch and file the documents whose answers have landed.
     *
     * Returns the number of requests closed out, answered or not.
     */
    public function collect(): int
    {
        $batchIds = DocumentClassificationRequest::query()
            ->submitted()
            ->whereNotNull('batch_id')
            ->distinct()
            ->pluck('batch_id');

        $closed = 0;

        foreach ($batchIds as $batchId) {
            $closed += $this->collectBatch((string) $batchId);
        }

        return $closed;
    }

    /**
     * The batch client for the provider classification is configured to run
     * on, or null when this deployment should not be batching at all.
     */
    protected function client(): ?BatchClient
    {
        if (! $this->classifier->batches()) {
            return null;
        }

        $client = $this->clients->for($this->classifier->batchProvider());

        return $client?->isConfigured() ? $client : null;
    }

    /**
     * Build one batch request per queued document, failing the ones that can
     * no longer be classified.
     *
     * @param  Collection<int, DocumentClassificationRequest>  $queued
     * @return array{0: array<int, array{custom_id: string, system: string, prompt: string, schema: array<string, mixed>}>, 1: array<int, int>}
     */
    protected function buildRequests(Collection $queued): array
    {
        $requests = [];
        $submitting = [];

        foreach ($queued as $request) {
            $document = $request->document;

            if ($document === null) {
                $request->markFailed('The document no longer exists.');

                continue;
            }

            $vocabulary = $this->classifier->vocabularyFor($document);

            if ($vocabulary->isEmpty()) {
                $request->markFailed('No categories are available to file this document under.');

                continue;
            }

            $agent = $this->classifier->agentFor($vocabulary);

            $requests[] = [
                'custom_id' => $request->customId(),
                'system' => $agent->instructions(),
                'prompt' => (string) $request->prompt,
                'schema' => $this->schemaFor($vocabulary->pluck('slug')->all()),
            ];

            $submitting[] = $request->id;
        }

        return [$requests, $submitting];
    }

    /**
     * The output shape, as structured outputs express it.
     *
     * Constraining `slug` to this user's vocabulary means the model cannot
     * name a category that does not exist for them. Numeric bounds are left
     * off `confidence` deliberately — structured outputs do not support
     * `minimum`/`maximum`, and the confidence floor is applied when the answer
     * is read anyway.
     *
     * @param  array<int, string>  $slugs
     * @return array<string, mixed>
     */
    protected function schemaFor(array $slugs): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'categories' => [
                    'type' => 'array',
                    'description' => 'The categories this document belongs to, most confident first. Empty when the document cannot be placed.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'slug' => ['type' => 'string', 'enum' => $slugs],
                            'confidence' => ['type' => 'number'],
                        ],
                        'required' => ['slug', 'confidence'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['categories'],
            'additionalProperties' => false,
        ];
    }

    /**
     * Poll one batch. A batch still running is left alone; an ended one is
     * read to completion and every request in it closed out.
     */
    protected function collectBatch(string $batchId): int
    {
        $client = $this->client();

        if ($client === null) {
            return 0;
        }

        try {
            $status = $client->status($batchId);
        } catch (Throwable $exception) {
            Log::warning('Document classification batch could not be polled.', [
                'batch_id' => $batchId,
                'exception' => $exception->getMessage(),
                'response' => $this->responseBody($exception),
            ]);

            return 0;
        }

        // Both providers expire results — Anthropic after 29 days, Gemini after
        // six weeks. Past that the batch is gone and the documents behind it
        // will never be answered.
        if ($status === null) {
            return $this->failRemaining($batchId, 'The batch is no longer available.');
        }

        if ($status !== BatchClient::STATUS_ENDED) {
            return 0;
        }

        try {
            $results = $client->results($batchId);
        } catch (Throwable $exception) {
            Log::warning('Document classification batch results could not be read.', [
                'batch_id' => $batchId,
                'exception' => $exception->getMessage(),
                'response' => $this->responseBody($exception),
            ]);

            return 0;
        }

        $requests = DocumentClassificationRequest::query()
            ->submitted()
            ->where('batch_id', $batchId)
            ->with('document')
            ->get()
            ->keyBy(fn (DocumentClassificationRequest $request): string => $request->customId());

        $closed = 0;

        foreach ($results as $result) {
            $customId = (string) ($result['custom_id'] ?? '');
            $request = $requests->get($customId);

            if ($request === null) {
                continue;
            }

            $this->applyResult($request, $result);
            $closed++;
        }

        // A request the batch never answered for. Rare, but it must not sit
        // submitted forever: closing it out leaves the document unfiled in the
        // Unfiled queue, where a person can still file it.
        $closed += $this->failRemaining($batchId, 'The batch ended without a result for this document.');

        return $closed;
    }

    /**
     * Apply one result to its document.
     *
     * @param  array<string, mixed>  $result
     */
    protected function applyResult(DocumentClassificationRequest $request, array $result): void
    {
        $status = (string) ($result['status'] ?? BatchClient::RESULT_ERRORED);

        if ($status !== BatchClient::RESULT_SUCCEEDED) {
            $request->markFailed(match ($status) {
                BatchClient::RESULT_EXPIRED => 'The batch expired before this document was classified.',
                BatchClient::RESULT_CANCELLED => 'The batch was cancelled.',
                default => 'The model returned an error for this document.',
            });

            return;
        }

        $document = $request->document;

        if ($document === null) {
            $request->markFailed('The document no longer exists.');

            return;
        }

        try {
            $this->classifier->apply(
                $document,
                $this->classifier->readJsonCandidates((string) ($result['text'] ?? '')),
            );

            $request->markSucceeded();
        } catch (Throwable $exception) {
            Log::warning('A classified document could not be filed.', [
                'document_id' => $document->id,
                'exception' => $exception->getMessage(),
            ]);

            $request->markFailed('The answer could not be applied.');
        }
    }

    /**
     * The provider's error body, when the failure was an HTTP error. The
     * exception message alone only says "HTTP request returned status code
     * 400" — the reason is in the body, and a log that cannot see it is a log
     * that cannot be acted on.
     */
    protected function responseBody(Throwable $exception): ?string
    {
        return $exception instanceof RequestException
            ? $exception->response->body()
            : null;
    }

    /**
     * Close out every request still waiting on a batch that will not answer
     * it, returning how many there were.
     */
    protected function failRemaining(string $batchId, string $reason): int
    {
        $stranded = DocumentClassificationRequest::query()
            ->submitted()
            ->where('batch_id', $batchId)
            ->get();

        foreach ($stranded as $request) {
            $request->markFailed($reason);
        }

        return $stranded->count();
    }
}
