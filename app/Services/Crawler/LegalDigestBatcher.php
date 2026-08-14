<?php

namespace App\Services\Crawler;

use App\Models\CrawledPage;
use App\Models\LegalDigestRequest;
use App\Services\Ai\BatchClient;
use App\Services\Ai\BatchClientFactory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Drives batched digesting: gathers the crawled authorities waiting on a
 * digest into one batch, and writes the digests when the answers land.
 *
 * Only the work nobody is watching goes through here — the nightly crawl and
 * the bulk backfill, which between them digest hundreds of pages at a time
 * that no reader has asked for yet. A digest generated because someone opened
 * a source stays inline: they are waiting on it, and a batch takes up to a day.
 *
 * The two halves run on their own schedules — submitting is cheap and can wait
 * for a worthwhile batch to accumulate, while collecting only needs to keep up
 * with batches ending.
 */
class LegalDigestBatcher
{
    public function __construct(
        private readonly LegalDigestService $digests,
        private readonly BatchClientFactory $clients,
    ) {
        //
    }

    /**
     * Queue a page for the next digest batch, replacing any request already
     * waiting for it. Returns null when this page has nothing to digest.
     */
    public function enqueue(CrawledPage $page, string $text): ?LegalDigestRequest
    {
        if (trim($text) === '') {
            return null;
        }

        return LegalDigestRequest::updateOrCreate(
            ['crawled_page_id' => $page->id],
            [
                'prompt' => $this->digests->promptFor($text, $page->title),
                'status' => LegalDigestRequest::STATUS_PENDING,
                'batch_id' => null,
                'error' => null,
                'submitted_at' => null,
                'completed_at' => null,
            ],
        );
    }

    /**
     * Submit the queued pages as one batch, returning its id — or null when
     * there was nothing worth sending.
     */
    public function submit(?int $limit = null): ?string
    {
        $client = $this->client();
        $model = $this->digests->batchModel();

        if ($client === null || $model === null) {
            return null;
        }

        $limit ??= (int) config('saligan.crawler.digest.batch.max_requests', 200);

        $queued = LegalDigestRequest::query()
            ->pending()
            ->with('page')
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
                (int) config('saligan.crawler.digest.batch.max_tokens', 2048),
                'legal-digest',
            );
        } catch (Throwable $exception) {
            // Left pending on purpose: a failed submission is a transport
            // problem, and the next sweep should try these pages again rather
            // than abandoning them undigested.
            Log::warning('Legal digest batch could not be submitted.', [
                'requests' => count($requests),
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }

        LegalDigestRequest::query()
            ->whereIn('id', $submitting)
            ->update([
                'status' => LegalDigestRequest::STATUS_SUBMITTED,
                'batch_id' => $batchId,
                'submitted_at' => now(),
                'updated_at' => now(),
            ]);

        return $batchId;
    }

    /**
     * Poll every open batch and write the digests whose answers have landed.
     *
     * Returns the number of requests closed out, answered or not.
     */
    public function collect(): int
    {
        $batchIds = LegalDigestRequest::query()
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
     * The batch client for the provider digests are configured to run on, or
     * null when this deployment should not be batching them at all.
     */
    protected function client(): ?BatchClient
    {
        if (! $this->digests->batches()) {
            return null;
        }

        $client = $this->clients->for($this->digests->batchProvider());

        return $client?->isConfigured() ? $client : null;
    }

    /**
     * Build one batch request per queued page, failing the ones that can no
     * longer be digested.
     *
     * @param  Collection<int, LegalDigestRequest>  $queued
     * @return array{0: array<int, array{custom_id: string, system: string, prompt: string, schema: null}>, 1: array<int, int>}
     */
    protected function buildRequests(Collection $queued): array
    {
        $instructions = $this->digests->instructions();

        $requests = [];
        $submitting = [];

        foreach ($queued as $request) {
            if ($request->page === null) {
                $request->markFailed('The page no longer exists.');

                continue;
            }

            $requests[] = [
                'custom_id' => $request->customId(),
                'system' => $instructions,
                'prompt' => (string) $request->prompt,
                // A digest is prose, not data. Asking for JSON back would only
                // wrap it in quotes and escapes for the reader to undo.
                'schema' => null,
            ];

            $submitting[] = $request->id;
        }

        return [$requests, $submitting];
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
            Log::warning('Legal digest batch could not be polled.', [
                'batch_id' => $batchId,
                'exception' => $exception->getMessage(),
            ]);

            return 0;
        }

        if ($status === null) {
            return $this->failRemaining($batchId, 'The batch is no longer available.');
        }

        if ($status !== BatchClient::STATUS_ENDED) {
            return 0;
        }

        try {
            $results = $client->results($batchId);
        } catch (Throwable $exception) {
            Log::warning('Legal digest batch results could not be read.', [
                'batch_id' => $batchId,
                'exception' => $exception->getMessage(),
            ]);

            return 0;
        }

        $requests = LegalDigestRequest::query()
            ->submitted()
            ->where('batch_id', $batchId)
            ->with('page')
            ->get()
            ->keyBy(fn (LegalDigestRequest $request): string => $request->customId());

        $closed = 0;

        foreach ($results as $result) {
            $request = $requests->get((string) ($result['custom_id'] ?? ''));

            if ($request === null) {
                continue;
            }

            $this->applyResult($request, $result);
            $closed++;
        }

        // A request the batch never answered for. Rare, but it must not sit
        // submitted forever: closing it out leaves the page undigested, which
        // the reader already handles by falling back to full text.
        $closed += $this->failRemaining($batchId, 'The batch ended without a result for this page.');

        return $closed;
    }

    /**
     * Write one result to its page.
     *
     * @param  array<string, mixed>  $result
     */
    protected function applyResult(LegalDigestRequest $request, array $result): void
    {
        $status = (string) ($result['status'] ?? BatchClient::RESULT_ERRORED);

        if ($status !== BatchClient::RESULT_SUCCEEDED) {
            $request->markFailed(match ($status) {
                BatchClient::RESULT_EXPIRED => 'The batch expired before this page was digested.',
                BatchClient::RESULT_CANCELLED => 'The batch was cancelled.',
                default => 'The model returned an error for this page.',
            });

            return;
        }

        $page = $request->page;

        if ($page === null) {
            $request->markFailed('The page no longer exists.');

            return;
        }

        $digest = $this->digests->read((string) ($result['text'] ?? ''));

        // NO_DIGEST is a real answer: the model read an index or an error page
        // rather than an authority. The request is done, and the page keeps no
        // digest — re-queueing it would just buy the same answer again.
        if ($digest === null) {
            $request->markSucceeded();

            return;
        }

        $page->forceFill([
            'digest' => $digest,
            'digest_generated_at' => now(),
        ])->save();

        $request->markSucceeded();
    }

    /**
     * Close out every request still waiting on a batch that will not answer
     * it, returning how many there were.
     */
    protected function failRemaining(string $batchId, string $reason): int
    {
        $stranded = LegalDigestRequest::query()
            ->submitted()
            ->where('batch_id', $batchId)
            ->get();

        foreach ($stranded as $request) {
            $request->markFailed($reason);
        }

        return $stranded->count();
    }
}
