<?php

use App\Ai\DocumentCategoryAgent;
use App\Models\Document;
use App\Models\DocumentClassificationRequest;
use App\Models\Label;
use App\Models\User;
use App\Services\Documents\DocumentClassificationBatcher;
use App\Services\Documents\DocumentClassifier;
use Database\Seeders\LabelSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    (new LabelSeeder)->run();

    config([
        'saligan.documents.classification.enabled' => true,
        'saligan.documents.classification.provider' => 'anthropic',
        // Pinned rather than left to the provider default: DOCUMENT_CLASSIFICATION_MODEL
        // is set in most real .env files, and a test that asserts on the model
        // must not depend on whose machine it runs on.
        'saligan.documents.classification.model' => 'claude-haiku-4-5-20251001',
        'saligan.documents.classification.batch.enabled' => true,
        'ai.providers.anthropic.key' => 'sk-ant-test',
        'ai.providers.anthropic.url' => 'https://api.anthropic.com/v1',
    ]);

    $this->user = User::factory()->create();

    $this->document = Document::factory()->for($this->user)->create([
        'original_filename' => 'judicial-affidavit-cruz.pdf',
        'title' => 'Judicial Affidavit of Cruz',
    ]);

    $this->excerpt = 'JUDICIAL AFFIDAVIT of JUAN CRUZ, of legal age, Filipino, after having been duly sworn...';

    $this->classify = fn (?Document $document = null) => app(DocumentClassifier::class)
        ->classify($document ?? $this->document, $this->excerpt);

    // The batch as Anthropic currently sees it. Held as state rather than
    // re-faked per call: Http::fake() appends stubs instead of replacing them,
    // so a second fake would never win against the first.
    $this->batchStatus = 'in_progress';
    $this->batchResults = [];
    $this->retrieveStatus = 200;
    $this->createStatus = 200;

    // The three endpoints the batcher speaks to. Results are JSONL, so that
    // fake answers with a raw body rather than a JSON array.
    Http::fake([
        '*/messages/batches/*/results' => fn () => Http::response(implode("\n", array_map(
            fn (array $line): string => json_encode($line),
            $this->batchResults,
        ))),
        '*/messages/batches/*' => fn () => $this->retrieveStatus === 200
            ? Http::response(['id' => 'msgbatch_1', 'processing_status' => $this->batchStatus])
            : Http::response(['error' => 'not found'], $this->retrieveStatus),
        '*/messages/batches' => fn () => $this->createStatus === 200
            ? Http::response(['id' => 'msgbatch_1', 'processing_status' => 'in_progress'])
            : Http::response(['error' => 'overloaded'], $this->createStatus),
    ]);

    $this->batchEnds = function (array $results = []): void {
        $this->batchStatus = 'ended';
        $this->batchResults = $results;
    };

    $this->succeededResult = fn (DocumentClassificationRequest $request, array $categories): array => [
        'custom_id' => $request->customId(),
        'result' => [
            'type' => 'succeeded',
            'message' => [
                'content' => [['type' => 'text', 'text' => json_encode(['categories' => $categories])]],
            ],
        ],
    ];
});

it('queues a document instead of classifying it inline when batching is on', function () {
    ($this->classify)();

    $request = DocumentClassificationRequest::sole();

    expect($request->document_id)->toBe($this->document->id)
        ->and($request->status)->toBe(DocumentClassificationRequest::STATUS_PENDING)
        ->and($request->prompt)->toContain('JUDICIAL AFFIDAVIT of JUAN CRUZ')
        ->and($this->document->fresh()->labels)->toBeEmpty();

    Http::assertNothingSent();
});

it('keeps classifying inline when the provider has no batches API', function () {
    config(['saligan.documents.classification.provider' => 'openai', 'ai.providers.openai.key' => 'test']);

    DocumentCategoryAgent::fake([
        ['categories' => [['slug' => 'evidence-testimonial', 'confidence' => 0.94]]],
    ]);

    ($this->classify)();

    expect(DocumentClassificationRequest::count())->toBe(0)
        ->and($this->document->fresh()->labels->pluck('slug')->all())->toBe(['evidence-testimonial']);
});

it('stores the queued prompt encrypted at rest', function () {
    ($this->classify)();

    $stored = (string) DB::table('document_classification_requests')->value('prompt');

    // Encrypted, so the document's text is not readable straight off the row —
    // the documents themselves are stored encrypted and this excerpt of one
    // must not be the hole in that.
    expect($stored)->not->toContain('JUDICIAL AFFIDAVIT')
        ->and(DocumentClassificationRequest::sole()->prompt)->toContain('JUDICIAL AFFIDAVIT');
});

it('re-queues a document rather than queueing it twice', function () {
    ($this->classify)();
    app(DocumentClassifier::class)->classify($this->document, 'A different extraction of the same file.');

    expect(DocumentClassificationRequest::count())->toBe(1)
        ->and(DocumentClassificationRequest::sole()->prompt)->toContain('A different extraction');
});

it('submits the queued documents as one batch', function () {
    ($this->classify)();

    $batchId = app(DocumentClassificationBatcher::class)->submit();

    expect($batchId)->toBe('msgbatch_1');

    $request = DocumentClassificationRequest::sole();

    expect($request->status)->toBe(DocumentClassificationRequest::STATUS_SUBMITTED)
        ->and($request->batch_id)->toBe('msgbatch_1')
        ->and($request->submitted_at)->not->toBeNull();

    Http::assertSent(function (Request $sent) use ($request): bool {
        if (! str_ends_with($sent->url(), '/messages/batches') || $sent->method() !== 'POST') {
            return false;
        }

        $payload = $sent->data()['requests'][0];
        $schema = $payload['params']['output_config']['format']['schema'];

        return $payload['custom_id'] === $request->customId()
            && $payload['params']['model'] === 'claude-haiku-4-5-20251001'
            && str_contains($payload['params']['system'], 'Philippine legal secretary')
            && str_contains($payload['params']['messages'][0]['content'], 'JUDICIAL AFFIDAVIT')
            && $schema['type'] === 'object'
            && in_array('evidence-testimonial', $schema['properties']['categories']['items']['properties']['slug']['enum'], true)
            && $sent->header('x-api-key')[0] === 'sk-ant-test'
            && $sent->header('anthropic-version')[0] === '2023-06-01';
    });
});

it('submits nothing when no documents are queued', function () {
    expect(app(DocumentClassificationBatcher::class)->submit())->toBeNull();

    Http::assertNothingSent();
});

it('leaves a request submitted while its batch is still running', function () {
    ($this->classify)();
    app(DocumentClassificationBatcher::class)->submit();
    app(DocumentClassificationBatcher::class)->collect();

    expect(DocumentClassificationRequest::sole()->status)
        ->toBe(DocumentClassificationRequest::STATUS_SUBMITTED);
});

it('files a document once its batch has ended', function () {
    ($this->classify)();
    app(DocumentClassificationBatcher::class)->submit();

    $request = DocumentClassificationRequest::sole();

    ($this->batchEnds)([
        ($this->succeededResult)($request, [
            ['slug' => 'evidence-testimonial', 'confidence' => 0.94],
            ['slug' => 'correspondence', 'confidence' => 0.12],
        ]),
    ]);

    expect(app(DocumentClassificationBatcher::class)->collect())->toBe(1);

    $labels = $this->document->fresh()->labels;

    // The confidence floor still applies: the same answer read inline would
    // have dropped the second category too.
    expect($labels->pluck('slug')->all())->toBe(['evidence-testimonial'])
        ->and($labels->first()->pivot->source)->toBe('ai')
        ->and((float) $labels->first()->pivot->confidence)->toBe(0.94)
        ->and($request->fresh()->status)->toBe(DocumentClassificationRequest::STATUS_SUCCEEDED);
});

it('never overwrites a filing made by hand while the batch was running', function () {
    ($this->classify)();
    app(DocumentClassificationBatcher::class)->submit();

    $request = DocumentClassificationRequest::sole();

    // The lawyer files it themselves before the answer comes back.
    $pleading = Label::where('slug', 'pleading')->sole();
    $this->document->labels()->attach($pleading->id, ['source' => 'user', 'assigned_by' => $this->user->id]);

    ($this->batchEnds)([
        ($this->succeededResult)($request, [['slug' => 'evidence-testimonial', 'confidence' => 0.99]]),
    ]);

    app(DocumentClassificationBatcher::class)->collect();

    expect($this->document->fresh()->labels->pluck('slug')->all())->toBe(['pleading']);
});

it('closes out a request the model could not answer', function () {
    ($this->classify)();
    app(DocumentClassificationBatcher::class)->submit();

    $request = DocumentClassificationRequest::sole();

    ($this->batchEnds)([
        ['custom_id' => $request->customId(), 'result' => ['type' => 'expired']],
    ]);

    app(DocumentClassificationBatcher::class)->collect();

    expect($request->fresh()->status)->toBe(DocumentClassificationRequest::STATUS_FAILED)
        ->and($request->fresh()->error)->toContain('expired')
        ->and($this->document->fresh()->labels)->toBeEmpty();
});

it('closes out a request whose batch ended without answering it', function () {
    ($this->classify)();
    app(DocumentClassificationBatcher::class)->submit();

    ($this->batchEnds)();

    app(DocumentClassificationBatcher::class)->collect();

    expect(DocumentClassificationRequest::sole()->status)
        ->toBe(DocumentClassificationRequest::STATUS_FAILED);
});

it('closes out a request whose batch is no longer available', function () {
    ($this->classify)();
    app(DocumentClassificationBatcher::class)->submit();

    $this->retrieveStatus = 404;

    app(DocumentClassificationBatcher::class)->collect();

    expect(DocumentClassificationRequest::sole()->status)
        ->toBe(DocumentClassificationRequest::STATUS_FAILED);
});

it('leaves a request pending when the batch cannot be submitted', function () {
    ($this->classify)();

    $this->createStatus = 529;

    expect(app(DocumentClassificationBatcher::class)->submit())->toBeNull()
        ->and(DocumentClassificationRequest::sole()->status)->toBe(DocumentClassificationRequest::STATUS_PENDING);
});

it('leaves the document unfiled when the answer is not usable JSON', function () {
    ($this->classify)();
    app(DocumentClassificationBatcher::class)->submit();

    $request = DocumentClassificationRequest::sole();

    ($this->batchEnds)([[
        'custom_id' => $request->customId(),
        'result' => [
            'type' => 'succeeded',
            'message' => ['content' => [['type' => 'text', 'text' => 'Sorry, I cannot tell.']]],
        ],
    ]]);

    app(DocumentClassificationBatcher::class)->collect();

    expect($this->document->fresh()->labels)->toBeEmpty()
        ->and($request->fresh()->status)->toBe(DocumentClassificationRequest::STATUS_SUCCEEDED);
});
