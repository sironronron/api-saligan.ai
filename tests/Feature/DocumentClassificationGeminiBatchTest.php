<?php

use App\Models\Document;
use App\Models\DocumentClassificationRequest;
use App\Models\User;
use App\Services\Documents\DocumentClassificationBatcher;
use App\Services\Documents\DocumentClassifier;
use Database\Seeders\LabelSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Monolog\Handler\TestHandler;
use Monolog\Logger as MonologLogger;

/*
 * The same batched-classification behaviour as the Anthropic suite, driven
 * through Gemini's batch API instead. The two providers agree on nothing but
 * the idea — request envelope, status vocabulary and result nesting all differ
 * — so the mapping needs its own coverage rather than an assumption that one
 * client standing in for the other proves anything.
 */

beforeEach(function () {
    (new LabelSeeder)->run();

    config([
        'saligan.documents.classification.enabled' => true,
        'saligan.documents.classification.provider' => 'gemini',
        'saligan.documents.classification.model' => 'gemini-3.6-flash',
        'saligan.documents.classification.batch.enabled' => true,
        'ai.providers.gemini.key' => 'gemini-test-key',
        'ai.providers.gemini.url' => 'https://generativelanguage.googleapis.com/v1beta/',
    ]);

    $this->user = User::factory()->create();

    $this->document = Document::factory()->for($this->user)->create([
        'original_filename' => 'judicial-affidavit-cruz.pdf',
        'title' => 'Judicial Affidavit of Cruz',
    ]);

    $this->excerpt = 'JUDICIAL AFFIDAVIT of JUAN CRUZ, of legal age, Filipino, after having been duly sworn...';

    $this->classify = fn () => app(DocumentClassifier::class)->classify($this->document, $this->excerpt);

    // Held as state rather than re-faked per call: Http::fake() appends stubs
    // instead of replacing them, so a second fake would never win.
    $this->state = 'JOB_STATE_RUNNING';
    $this->inlined = [];
    $this->pollStatus = 200;
    $this->createStatus = 200;

    Http::fake([
        '*:batchGenerateContent' => fn () => $this->createStatus === 200
            ? Http::response(['name' => 'batches/abc123'])
            : Http::response(['error' => 'unavailable'], $this->createStatus),
        '*/batches/*' => fn () => $this->pollStatus === 200
            ? Http::response([
                'name' => 'batches/abc123',
                'metadata' => ['state' => $this->state],
                'response' => ['inlinedResponses' => $this->inlined],
            ])
            : Http::response(['error' => 'not found'], $this->pollStatus),
    ]);

    $this->batchEnds = function (array $inlined = []): void {
        $this->state = 'JOB_STATE_SUCCEEDED';
        $this->inlined = $inlined;
    };

    $this->answer = fn (DocumentClassificationRequest $request, array $categories): array => [
        'metadata' => ['key' => $request->customId()],
        'response' => [
            'candidates' => [[
                'content' => ['parts' => [['text' => json_encode(['categories' => $categories])]]],
            ]],
        ],
    ];
});

it('queues a document rather than classifying it inline', function () {
    ($this->classify)();

    expect(DocumentClassificationRequest::sole()->status)
        ->toBe(DocumentClassificationRequest::STATUS_PENDING);

    Http::assertNothingSent();
});

it('submits the queued documents as one Gemini batch', function () {
    ($this->classify)();

    expect(app(DocumentClassificationBatcher::class)->submit())->toBe('batches/abc123');

    $request = DocumentClassificationRequest::sole();

    expect($request->status)->toBe(DocumentClassificationRequest::STATUS_SUBMITTED)
        ->and($request->batch_id)->toBe('batches/abc123');

    Http::assertSent(function (Request $sent) use ($request): bool {
        if (! str_contains($sent->url(), ':batchGenerateContent') || $sent->method() !== 'POST') {
            return false;
        }

        $inline = $sent->data()['batch']['input_config']['requests']['requests'][0];
        $config = $inline['request']['generation_config'];

        return str_contains($sent->url(), 'models/gemini-3.6-flash:batchGenerateContent')
            // The only handle on which answer belongs to which document.
            && $inline['metadata']['key'] === $request->customId()
            && str_contains($inline['request']['system_instruction']['parts'][0]['text'], 'Philippine legal secretary')
            && str_contains($inline['request']['contents'][0]['parts'][0]['text'], 'JUDICIAL AFFIDAVIT')
            && $config['response_mime_type'] === 'application/json'
            && in_array(
                'evidence-testimonial',
                $config['response_schema']['properties']['categories']['items']['properties']['slug']['enum'],
                true,
            )
            // Gemini's Schema proto has no `additionalProperties` keyword — the
            // shared classification schema carries it for Anthropic's sake, and
            // this client must not hand it to an API that rejects it.
            && ! array_key_exists('additionalProperties', $config['response_schema'])
            && ! array_key_exists('additionalProperties', $config['response_schema']['properties']['categories']['items'])
            && $sent->header('x-goog-api-key')[0] === 'gemini-test-key';
    });
});

it('leaves a request submitted while the job is still running', function () {
    ($this->classify)();
    app(DocumentClassificationBatcher::class)->submit();
    app(DocumentClassificationBatcher::class)->collect();

    expect(DocumentClassificationRequest::sole()->status)
        ->toBe(DocumentClassificationRequest::STATUS_SUBMITTED);
});

it('files a document once its job has succeeded', function () {
    ($this->classify)();
    app(DocumentClassificationBatcher::class)->submit();

    $request = DocumentClassificationRequest::sole();

    ($this->batchEnds)([
        ($this->answer)($request, [
            ['slug' => 'evidence-testimonial', 'confidence' => 0.94],
            ['slug' => 'correspondence', 'confidence' => 0.12],
        ]),
    ]);

    expect(app(DocumentClassificationBatcher::class)->collect())->toBe(1);

    $labels = $this->document->fresh()->labels;

    // The confidence floor applies exactly as it does inline.
    expect($labels->pluck('slug')->all())->toBe(['evidence-testimonial'])
        ->and((float) $labels->first()->pivot->confidence)->toBe(0.94)
        ->and($request->fresh()->status)->toBe(DocumentClassificationRequest::STATUS_SUCCEEDED);
});

/*
 * Some shapes of the API wrap the list in a second `inlinedResponses` object
 * and put the union under `output`. Both read the same way, because getting it
 * wrong files nothing and says nothing.
 */
it('reads results from the nested response shape too', function () {
    ($this->classify)();
    app(DocumentClassificationBatcher::class)->submit();

    $request = DocumentClassificationRequest::sole();

    ($this->batchEnds)([
        'inlinedResponses' => [[
            'metadata' => ['key' => $request->customId()],
            'output' => [
                'response' => [
                    'candidates' => [[
                        'content' => ['parts' => [[
                            'text' => json_encode(['categories' => [['slug' => 'pleading', 'confidence' => 0.91]]]),
                        ]]],
                    ]],
                ],
            ],
        ]],
    ]);

    app(DocumentClassificationBatcher::class)->collect();

    expect($this->document->fresh()->labels->pluck('slug')->all())->toBe(['pleading']);
});

it('closes out a request the model returned an error for', function () {
    ($this->classify)();
    app(DocumentClassificationBatcher::class)->submit();

    $request = DocumentClassificationRequest::sole();

    ($this->batchEnds)([[
        'metadata' => ['key' => $request->customId()],
        'error' => ['code' => 3, 'message' => 'Invalid argument.'],
    ]]);

    app(DocumentClassificationBatcher::class)->collect();

    expect($request->fresh()->status)->toBe(DocumentClassificationRequest::STATUS_FAILED)
        ->and($this->document->fresh()->labels)->toBeEmpty();
});

/*
 * A job that failed outright answers nothing. The requests behind it must not
 * sit submitted forever — closing them out leaves the documents in the Unfiled
 * queue, where a person can still file them.
 */
it('closes out every request when the job failed outright', function () {
    ($this->classify)();
    app(DocumentClassificationBatcher::class)->submit();

    $this->state = 'JOB_STATE_FAILED';

    app(DocumentClassificationBatcher::class)->collect();

    expect(DocumentClassificationRequest::sole()->status)
        ->toBe(DocumentClassificationRequest::STATUS_FAILED);
});

it('closes out a request whose job is no longer available', function () {
    ($this->classify)();
    app(DocumentClassificationBatcher::class)->submit();

    $this->pollStatus = 404;

    app(DocumentClassificationBatcher::class)->collect();

    expect(DocumentClassificationRequest::sole()->status)
        ->toBe(DocumentClassificationRequest::STATUS_FAILED);
});

it('leaves a request pending when the batch cannot be submitted', function () {
    $handler = new TestHandler;

    config([
        'logging.channels.array' => [
            'driver' => 'custom',
            'via' => fn (array $config) => new MonologLogger('array', [$handler]),
        ],
        'logging.default' => 'array',
    ]);

    ($this->classify)();

    $this->createStatus = 400;

    expect(app(DocumentClassificationBatcher::class)->submit())->toBeNull()
        ->and(DocumentClassificationRequest::sole()->status)
        ->toBe(DocumentClassificationRequest::STATUS_PENDING)
        ->and($handler->hasWarningThatContains('could not be submitted'))->toBeTrue();
});

it('classifies inline when Gemini has no API key, batching or not', function () {
    config(['ai.providers.gemini.key' => '']);

    expect(app(DocumentClassifier::class)->batches())->toBeFalse();
});
