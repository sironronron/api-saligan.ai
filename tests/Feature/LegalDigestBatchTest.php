<?php

use App\Enums\CrawlStatus;
use App\Jobs\CrawlLegalSourcePage;
use App\Models\CrawledPage;
use App\Models\LegalChunk;
use App\Models\LegalDigestRequest;
use App\Models\LegalSource;
use App\Models\User;
use App\Services\Ai\EmbeddingService;
use App\Services\Crawler\CrawlerAdapterFactory;
use App\Services\Crawler\LegalDigestBatcher;
use App\Services\Crawler\LegalDigestService;
use App\Services\Crawler\RobotsTxt;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * Batched digesting covers the bulk producers only — the nightly crawl and the
 * backfill. The read-path digest stays inline, and has its own coverage; the
 * point of these tests is that the split holds.
 */

beforeEach(function () {
    config([
        'saligan.crawler.digest.provider' => 'gemini',
        'saligan.crawler.digest.model' => 'gemini-3.6-flash',
        'saligan.crawler.digest.batch.enabled' => true,
        'ai.providers.gemini.key' => 'gemini-test-key',
        'ai.providers.gemini.url' => 'https://generativelanguage.googleapis.com/v1beta/',
    ]);

    $this->page = CrawledPage::factory()->create([
        'title' => 'People v. Dela Cruz, G.R. No. 123456',
    ]);

    $this->text = 'DECISION. This is an appeal from the Court of Appeals affirming the conviction of the accused...';

    $this->state = 'JOB_STATE_RUNNING';
    $this->inlined = [];

    Http::fake([
        '*:batchGenerateContent' => fn () => Http::response(['name' => 'batches/digest1']),
        '*/batches/*' => fn () => Http::response([
            'name' => 'batches/digest1',
            'metadata' => ['state' => $this->state],
            'response' => ['inlinedResponses' => $this->inlined],
        ]),
    ]);

    $this->batchEnds = function (array $inlined = []): void {
        $this->state = 'JOB_STATE_SUCCEEDED';
        $this->inlined = $inlined;
    };

    $this->answer = fn (LegalDigestRequest $request, string $text): array => [
        'metadata' => ['key' => $request->customId()],
        'response' => ['candidates' => [['content' => ['parts' => [['text' => $text]]]]]],
    ];

    $this->digest = "Nature: An appeal from the Court of Appeals.\nFacts: The accused was convicted...";
});

it('queues a page rather than digesting it', function () {
    app(LegalDigestBatcher::class)->enqueue($this->page, $this->text);

    $request = LegalDigestRequest::sole();

    expect($request->crawled_page_id)->toBe($this->page->id)
        ->and($request->status)->toBe(LegalDigestRequest::STATUS_PENDING)
        ->and($request->prompt)->toContain('People v. Dela Cruz')
        ->and($this->page->fresh()->digest)->toBeNull();

    Http::assertNothingSent();
});

it('re-queues a page rather than queueing it twice', function () {
    app(LegalDigestBatcher::class)->enqueue($this->page, $this->text);
    app(LegalDigestBatcher::class)->enqueue($this->page, 'A later crawl of the same authority.');

    expect(LegalDigestRequest::count())->toBe(1)
        ->and(LegalDigestRequest::sole()->prompt)->toContain('A later crawl');
});

it('queues nothing for a page with no text', function () {
    expect(app(LegalDigestBatcher::class)->enqueue($this->page, '   '))->toBeNull()
        ->and(LegalDigestRequest::count())->toBe(0);
});

it('submits the queued pages as one batch, asking for prose rather than JSON', function () {
    app(LegalDigestBatcher::class)->enqueue($this->page, $this->text);

    expect(app(LegalDigestBatcher::class)->submit())->toBe('batches/digest1');

    $request = LegalDigestRequest::sole();

    expect($request->status)->toBe(LegalDigestRequest::STATUS_SUBMITTED)
        ->and($request->batch_id)->toBe('batches/digest1');

    Http::assertSent(function (Request $sent) use ($request): bool {
        if (! str_contains($sent->url(), ':batchGenerateContent')) {
            return false;
        }

        $body = $sent->data()['batch'];
        $inline = $body['input_config']['requests']['requests'][0];

        return $inline['metadata']['key'] === $request->customId()
            && str_contains($inline['request']['system_instruction']['parts'][0]['text'], 'digests of Philippine legal authorities')
            && str_contains($inline['request']['contents'][0]['parts'][0]['text'], 'People v. Dela Cruz')
            // A digest is prose. Asking for JSON back would only wrap it in
            // quotes and escapes for the reader to undo.
            && ! array_key_exists('response_schema', $inline['request']['generation_config'])
            && ! array_key_exists('response_mime_type', $inline['request']['generation_config'])
            // Labelled so a digest batch is not mistaken for a classification
            // one in Google's console.
            && str_starts_with($body['display_name'], 'legal-digest-');
    });
});

it('writes the digest once the batch has ended', function () {
    app(LegalDigestBatcher::class)->enqueue($this->page, $this->text);
    app(LegalDigestBatcher::class)->submit();

    $request = LegalDigestRequest::sole();

    ($this->batchEnds)([($this->answer)($request, $this->digest)]);

    expect(app(LegalDigestBatcher::class)->collect())->toBe(1);

    $page = $this->page->fresh();

    expect($page->digest)->toBe($this->digest)
        ->and($page->digest_generated_at)->not->toBeNull()
        ->and($request->fresh()->status)->toBe(LegalDigestRequest::STATUS_SUCCEEDED);
});

/*
 * NO_DIGEST is a real answer — the model read an index or an error page rather
 * than an authority. The request is done, not failed: re-queueing it would buy
 * the same answer again.
 */
it('closes out a page the model declined to digest, without failing it', function () {
    app(LegalDigestBatcher::class)->enqueue($this->page, $this->text);
    app(LegalDigestBatcher::class)->submit();

    $request = LegalDigestRequest::sole();

    ($this->batchEnds)([($this->answer)($request, 'NO_DIGEST')]);

    app(LegalDigestBatcher::class)->collect();

    expect($this->page->fresh()->digest)->toBeNull()
        ->and($request->fresh()->status)->toBe(LegalDigestRequest::STATUS_SUCCEEDED);
});

it('leaves a request submitted while the job is still running', function () {
    app(LegalDigestBatcher::class)->enqueue($this->page, $this->text);
    app(LegalDigestBatcher::class)->submit();
    app(LegalDigestBatcher::class)->collect();

    expect(LegalDigestRequest::sole()->status)->toBe(LegalDigestRequest::STATUS_SUBMITTED);
});

it('closes out a request whose batch ended without answering it', function () {
    app(LegalDigestBatcher::class)->enqueue($this->page, $this->text);
    app(LegalDigestBatcher::class)->submit();

    ($this->batchEnds)();

    app(LegalDigestBatcher::class)->collect();

    expect(LegalDigestRequest::sole()->status)->toBe(LegalDigestRequest::STATUS_FAILED)
        ->and($this->page->fresh()->digest)->toBeNull();
});

/*
 * The crawl is the bulk producer this exists for: a nightly run fetches
 * hundreds of authorities nobody has asked for, and digesting each inline is
 * the spend the batch discount is meant to halve.
 */
it('queues from the crawl instead of digesting each page inline', function () {
    $source = LegalSource::factory()->create(['base_domain' => 'lawphil.net']);

    Http::fake([
        '*/robots.txt' => Http::response("User-agent: *\nDisallow:\n", 200),
        '*/api/embed' => Http::response(fakeEmbedResponse(), 200),
        '*:batchGenerateContent' => Http::response(['name' => 'batches/digest1']),
        '*' => Http::response('<html><body><p>Republic Act No. 6657 coverage rules.</p></body></html>', 200),
    ]);

    (new CrawlLegalSourcePage($source, 'https://lawphil.net/statutes/repacts/repacts.html'))
        ->handle(app(EmbeddingService::class), new CrawlerAdapterFactory, new RobotsTxt);

    $request = LegalDigestRequest::sole();

    expect($request->status)->toBe(LegalDigestRequest::STATUS_PENDING)
        ->and($request->page->digest)->toBeNull()
        ->and($request->prompt)->toContain('Republic Act No. 6657');

    // Queued, not sent — submitting is the scheduled sweep's job, so a crawl
    // of 500 pages still costs exactly one batch rather than 500 calls.
    Http::assertNotSent(fn (Request $sent): bool => str_contains($sent->url(), ':batchGenerateContent'));
});

it('does not batch when the digest provider has no batch API', function () {
    config(['saligan.crawler.digest.provider' => 'ollama']);

    expect(app(LegalDigestService::class)->batches())->toBeFalse()
        ->and(app(LegalDigestBatcher::class)->submit())->toBeNull();
});

it('does not batch when digesting is switched off entirely', function () {
    config(['saligan.crawler.digest.provider' => 'none']);

    expect(app(LegalDigestService::class)->batches())->toBeFalse();
});

it('does not batch when the flag is off', function () {
    config(['saligan.crawler.digest.batch.enabled' => false]);

    expect(app(LegalDigestService::class)->batches())->toBeFalse();
});

/*
 * The whole point of the split: batching is for work nobody is waiting on. A
 * reader who opens an undigested source is waiting, so that digest is still
 * written on the spot — the batch flag must not reach the read path and leave
 * them waiting a day for a summary.
 */
it('never queues a digest for a reader opening an undigested source', function () {
    expect(app(LegalDigestService::class)->batches())->toBeTrue();

    $page = CrawledPage::factory()->for(LegalSource::factory())->create([
        'digest' => null,
        'crawl_status' => CrawlStatus::Ok,
    ]);

    LegalChunk::factory()->for($page)->create([
        'chunk_index' => 0,
        'content' => 'Section 2. Declaration of policy.',
    ]);

    $this->signInAs(User::factory()->create())
        ->getJson("/api/legal-pages/{$page->id}")
        ->assertOk();

    expect(LegalDigestRequest::count())->toBe(0);
});
