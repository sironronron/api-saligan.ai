<?php

namespace App\Services\Ai;

/**
 * A provider's batch API, reduced to the four things batched classification
 * needs from one.
 *
 * Anthropic and Gemini both offer half-price asynchronous batches, but they
 * agree on nothing else: the request envelope, the status vocabulary, the
 * result nesting and the way a request is identified all differ. Normalizing
 * here rather than in the caller keeps that entirely inside the client, so
 * DocumentClassificationBatcher describes classification work and never
 * provider shapes.
 */
interface BatchClient
{
    /** The batch is still being worked on; results are not readable yet. */
    public const STATUS_RUNNING = 'running';

    /** Every request in the batch has reached a terminal state. */
    public const STATUS_ENDED = 'ended';

    /** The model answered. `text` carries the answer. */
    public const RESULT_SUCCEEDED = 'succeeded';

    /** The provider returned an error for this request. */
    public const RESULT_ERRORED = 'errored';

    /** The batch ran out of time before reaching this request. */
    public const RESULT_EXPIRED = 'expired';

    /** The batch was cancelled before reaching this request. */
    public const RESULT_CANCELLED = 'cancelled';

    /**
     * Whether the credentials needed to talk to this provider are configured.
     */
    public function isConfigured(): bool;

    /**
     * Submit a batch, returning the id the provider knows it by.
     *
     * Each request carries an instruction block, the text to read, and
     * optionally the JSON shape the answer must take — `schema` is null for
     * work whose answer is prose rather than data. `custom_id` is how the
     * answer is matched back; results come back in no guaranteed order, on
     * either provider.
     *
     * `$label` names the batch for whoever is looking at it in the provider's
     * own console. Providers that have no such field ignore it.
     *
     * @param  array<int, array{custom_id: string, system: string, prompt: string, schema: array<string, mixed>|null}>  $requests
     */
    public function create(array $requests, string $model, int $maxTokens, string $label = 'saligan'): string;

    /**
     * The batch's status, or null when the provider no longer knows about it —
     * both providers expire results eventually, after which a batch id is a
     * dead letter and the requests behind it will never be answered.
     */
    public function status(string $batchId): ?string;

    /**
     * The results of an ended batch, one entry per answered request.
     *
     * Keyed by the `custom_id` it was submitted with, never by position.
     *
     * @return array<int, array{custom_id: string, status: string, text: string}>
     */
    public function results(string $batchId): array;
}
