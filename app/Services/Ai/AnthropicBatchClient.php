<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * The Anthropic Message Batches API.
 *
 * Batched requests are billed at half the standard rate and answered within
 * the day rather than the second, which is the right trade for work nobody is
 * waiting on. The Laravel AI package this app uses elsewhere has no binding
 * for batches, so this speaks to the endpoint directly rather than adding a
 * second Anthropic client alongside it.
 *
 * @see https://platform.claude.com/docs/en/build-with-claude/batch-processing
 */
class AnthropicBatchClient implements BatchClient
{
    /**
     * Anthropic's own name for a batch still being worked on. Mapped to the
     * shared vocabulary by status(); results are only readable once the
     * processing status is `ended`.
     */
    public const PROCESSING_IN_PROGRESS = 'in_progress';

    /** Anthropic's own name for a batch whose requests have all finished. */
    public const PROCESSING_ENDED = 'ended';

    /**
     * Submit a batch, returning its id.
     *
     * @param  array<int, array{custom_id: string, system: string, prompt: string, schema: array<string, mixed>|null}>  $requests
     */
    public function create(array $requests, string $model, int $maxTokens, string $label = 'saligan'): string
    {
        // Anthropic batches carry no display name, so the label has nowhere to
        // go; the batch id is what shows up in the console.
        $response = $this->request()
            ->post('/messages/batches', [
                'requests' => array_map(fn (array $request): array => [
                    'custom_id' => $request['custom_id'],
                    'params' => array_merge([
                        'model' => $model,
                        'max_tokens' => $maxTokens,
                        'system' => $request['system'],
                        'messages' => [
                            ['role' => 'user', 'content' => $request['prompt']],
                        ],
                    ], ($request['schema'] ?? null) === null ? [] : [
                        'output_config' => [
                            'format' => [
                                'type' => 'json_schema',
                                'schema' => $request['schema'],
                            ],
                        ],
                    ]),
                ], $requests),
            ])
            ->throw();

        $id = $response->json('id');

        if (! is_string($id) || $id === '') {
            throw new RuntimeException('Anthropic accepted the batch but returned no id.');
        }

        return $id;
    }

    /**
     * The batch's status, or null when Anthropic no longer knows about it —
     * results are kept for 29 days, after which a batch id is a dead letter
     * and the requests behind it will never be answered.
     */
    public function status(string $batchId): ?string
    {
        $response = $this->request()->get("/messages/batches/{$batchId}");

        if ($response->notFound()) {
            return null;
        }

        return $response->throw()->json('processing_status') === self::PROCESSING_ENDED
            ? self::STATUS_ENDED
            : self::STATUS_RUNNING;
    }

    /**
     * The results of an ended batch, one entry per answered request.
     *
     * @return array<int, array{custom_id: string, status: string, text: string}>
     */
    public function results(string $batchId): array
    {
        $body = $this->request()
            ->get("/messages/batches/{$batchId}/results")
            ->throw()
            ->body();

        $results = [];

        // The endpoint answers in JSONL: one result per line.
        foreach (preg_split('/\r?\n/', trim($body)) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }

            $decoded = json_decode($line, true);

            if (! is_array($decoded)) {
                continue;
            }

            $results[] = [
                'custom_id' => (string) ($decoded['custom_id'] ?? ''),
                'status' => match ((string) ($decoded['result']['type'] ?? '')) {
                    'succeeded' => self::RESULT_SUCCEEDED,
                    'expired' => self::RESULT_EXPIRED,
                    'canceled' => self::RESULT_CANCELLED,
                    default => self::RESULT_ERRORED,
                },
                'text' => $this->textFrom($decoded),
            ];
        }

        return $results;
    }

    /**
     * The model's answer, as text. Structured outputs put the JSON in ordinary
     * text blocks, so the blocks are joined rather than indexed.
     *
     * @param  array<string, mixed>  $result
     */
    protected function textFrom(array $result): string
    {
        $content = $result['result']['message']['content'] ?? [];

        if (! is_array($content)) {
            return '';
        }

        $text = '';

        foreach ($content as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text') {
                $text .= (string) ($block['text'] ?? '');
            }
        }

        return $text;
    }

    /**
     * Cancel a batch that is still in progress. Requests already completed are
     * still billed and still readable.
     */
    public function cancel(string $batchId): void
    {
        $this->request()->post("/messages/batches/{$batchId}/cancel")->throw();
    }

    /**
     * Whether the API key needed to talk to Anthropic is configured at all.
     */
    public function isConfigured(): bool
    {
        return filled(config('ai.providers.anthropic.key'));
    }

    /**
     * A configured HTTP client. The base url carries the `/v1` prefix, so
     * paths here are relative to it.
     */
    protected function request(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('ai.providers.anthropic.url', 'https://api.anthropic.com/v1'), '/'))
            ->withHeaders([
                'x-api-key' => (string) config('ai.providers.anthropic.key'),
                'anthropic-version' => '2023-06-01',
            ])
            ->asJson()
            ->timeout((int) config('saligan.batch_timeout', 60))
            // Retry the failures that are worth retrying — a dropped
            // connection or a bad minute on Anthropic's side. A 4xx is an
            // answer, not a hiccup, and re-sending it three times only delays
            // handling it.
            ->retry(3, 500, function (Throwable $exception): bool {
                return $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && $exception->response->serverError());
            }, throw: false);
    }
}
