<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * The Gemini Batch API, in its inline-requests form.
 *
 * Same trade as Anthropic's: half price, answered within the day rather than
 * the second. Gemini offers a file-based form as well for very large jobs, but
 * inline requests carry a 20MB ceiling that a batch of classification excerpts
 * is nowhere near — a `max_requests` of 500 at a 6000-character excerpt each is
 * roughly 3MB — so the simpler shape is the right one here.
 *
 * The Laravel AI package this app uses elsewhere binds Gemini's synchronous
 * endpoints only (its `batchEmbedContents` call is a multi-input embedding
 * request, not this asynchronous batch API), so this speaks to the endpoint
 * directly.
 *
 * @see https://ai.google.dev/gemini-api/docs/batch-api
 */
class GeminiBatchClient implements BatchClient
{
    /**
     * Gemini's own job states. Everything terminal maps to STATUS_ENDED: a
     * batch that failed outright still needs its requests closed out, and the
     * empty result set does exactly that.
     */
    public const JOB_PENDING = 'JOB_STATE_PENDING';

    public const JOB_RUNNING = 'JOB_STATE_RUNNING';

    public const JOB_SUCCEEDED = 'JOB_STATE_SUCCEEDED';

    public function isConfigured(): bool
    {
        return filled(config('ai.providers.gemini.key'));
    }

    /**
     * Submit a batch, returning the resource name Gemini knows it by
     * (`batches/…`), which is also what the status and result calls take.
     *
     * @param  array<int, array{custom_id: string, system: string, prompt: string, schema: array<string, mixed>|null}>  $requests
     */
    public function create(array $requests, string $model, int $maxTokens, string $label = 'saligan'): string
    {
        $response = $this->request()
            ->post("models/{$model}:batchGenerateContent", [
                'batch' => [
                    'display_name' => $label.'-'.now()->format('Ymd-His'),
                    'input_config' => [
                        'requests' => [
                            'requests' => array_map(fn (array $request): array => [
                                'request' => [
                                    'system_instruction' => [
                                        'parts' => [['text' => $request['system']]],
                                    ],
                                    'contents' => [[
                                        'role' => 'user',
                                        'parts' => [['text' => $request['prompt']]],
                                    ]],
                                    'generation_config' => array_merge(
                                        ['max_output_tokens' => $maxTokens],
                                        // Prose answers — a digest — carry no
                                        // schema, and asking for JSON back
                                        // would only wrap them in quotes.
                                        ($request['schema'] ?? null) === null ? [] : [
                                            'response_mime_type' => 'application/json',
                                            'response_schema' => $request['schema'],
                                        ],
                                    ),
                                ],
                                // How the answer is matched back. Gemini echoes
                                // this on the result and gives no other handle
                                // on which request produced which answer.
                                'metadata' => ['key' => $request['custom_id']],
                            ], $requests),
                        ],
                    ],
                ],
            ])
            ->throw();

        $name = $response->json('name');

        if (! is_string($name) || $name === '') {
            throw new RuntimeException('Gemini accepted the batch but returned no name.');
        }

        return $name;
    }

    /**
     * The batch's status, or null when Gemini no longer knows about it —
     * results are kept for six weeks, after which the requests behind a batch
     * id will never be answered.
     */
    public function status(string $batchId): ?string
    {
        $response = $this->request()->get($this->path($batchId));

        if ($response->notFound()) {
            return null;
        }

        $state = (string) $response->throw()->json('metadata.state');

        return in_array($state, [self::JOB_PENDING, self::JOB_RUNNING], true)
            ? self::STATUS_RUNNING
            : self::STATUS_ENDED;
    }

    /**
     * The results of an ended batch, one entry per answered request.
     *
     * @return array<int, array{custom_id: string, status: string, text: string}>
     */
    public function results(string $batchId): array
    {
        $body = $this->request()->get($this->path($batchId))->throw()->json();

        $results = [];

        foreach ($this->inlinedResponses(is_array($body) ? $body : []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $customId = (string) ($entry['metadata']['key'] ?? '');

            if ($customId === '') {
                continue;
            }

            // The union is `response` or `error`, and some shapes of the API
            // wrap it in `output`. Unwrap first so both read the same way.
            $outcome = is_array($entry['output'] ?? null) ? $entry['output'] : $entry;

            $results[] = [
                'custom_id' => $customId,
                'status' => isset($outcome['response'])
                    ? self::RESULT_SUCCEEDED
                    : self::RESULT_ERRORED,
                'text' => $this->textFrom(is_array($outcome['response'] ?? null) ? $outcome['response'] : []),
            ];
        }

        return $results;
    }

    /**
     * The inline responses out of a batch resource.
     *
     * The array sits under `response.inlinedResponses`, which is itself
     * sometimes an object wrapping a second `inlinedResponses` list rather than
     * the list directly. Both are unwrapped here rather than at the call site,
     * so a shape change is one branch instead of a parsing bug that silently
     * files nothing.
     *
     * @param  array<string, mixed>  $body
     * @return array<int, mixed>
     */
    protected function inlinedResponses(array $body): array
    {
        $responses = data_get($body, 'response.inlinedResponses');

        if (! is_array($responses)) {
            return [];
        }

        if (is_array($responses['inlinedResponses'] ?? null)) {
            $responses = $responses['inlinedResponses'];
        }

        return array_is_list($responses) ? $responses : [];
    }

    /**
     * The model's answer, as text. A response carries its JSON in the parts of
     * the first candidate, which are joined rather than indexed.
     *
     * @param  array<string, mixed>  $response
     */
    protected function textFrom(array $response): string
    {
        $parts = data_get($response, 'candidates.0.content.parts');

        if (! is_array($parts)) {
            return '';
        }

        $text = '';

        foreach ($parts as $part) {
            if (is_array($part)) {
                $text .= (string) ($part['text'] ?? '');
            }
        }

        return $text;
    }

    /**
     * A batch resource name as a path relative to the configured base url.
     * Gemini returns it already qualified (`batches/…`); trimming any leading
     * slash keeps it from resolving against the host instead of the version.
     */
    protected function path(string $batchId): string
    {
        return ltrim($batchId, '/');
    }

    /**
     * A configured HTTP client. The base url carries the API version, so paths
     * here are relative to it.
     */
    protected function request(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('ai.providers.gemini.url', 'https://generativelanguage.googleapis.com/v1beta/'), '/').'/')
            ->withHeaders(['x-goog-api-key' => (string) config('ai.providers.gemini.key')])
            ->asJson()
            ->timeout((int) config('saligan.batch_timeout', 60))
            // Retry only what is worth retrying — a dropped connection or a bad
            // minute on Google's side. A 4xx is an answer, not a hiccup.
            ->retry(3, 500, function (Throwable $exception): bool {
                return $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && $exception->response->serverError());
            }, throw: false);
    }
}
