<?php

use App\Enums\CrawlStatus;
use App\Jobs\CrawlLegalSourcePage;
use App\Models\CrawledPage;
use App\Models\LegalChunk;
use App\Models\LegalSource;
use App\Services\Ai\EmbeddingService;
use App\Services\Crawler\CrawlerAdapterFactory;
use App\Services\Crawler\RobotsTxt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function fakeEmbedResponse(): array
{
    return ['embeddings' => [array_fill(0, 4000, 0.25)]];
}

it('marks a page as failed when blocked by robots.txt', function () {
    $source = LegalSource::factory()->create(['base_domain' => 'lawphil.net']);

    Http::fake([
        '*/robots.txt' => Http::response("User-agent: *\nDisallow: /private/\n", 200),
    ]);

    (new CrawlLegalSourcePage($source, 'https://lawphil.net/private/secret.html'))
        ->handle(
            app(EmbeddingService::class),
            new CrawlerAdapterFactory,
            new RobotsTxt,
        );

    $this->assertDatabaseHas('crawled_pages', [
        'legal_source_id' => $source->id,
        'crawl_status' => CrawlStatus::Failed->value,
        'last_error' => 'Blocked by robots.txt',
    ]);
});

it('parses a lawphil page, stores raw html, and indexes chunks', function () {
    Storage::fake('local');

    $source = LegalSource::factory()->create(['base_domain' => 'lawphil.net']);

    $html = <<<'HTML'
    <html>
    <head><title>G.R. No. 143491 - People v. Juan</title></head>
    <body>
    <p>Republic Act No. 6657, otherwise known as the Comprehensive Agrarian Reform Law, provides for the agrarian reform program of the Philippines.</p>
    <p>G.R. No. 143491 promulgated on June 10, 2003 deals with the coverage of the program.</p>
    <p><a href="/judjuris/judjuris.html">Jurisprudence index</a></p>
    </body>
    </html>
    HTML;

    Http::fake([
        '*/robots.txt' => Http::response("User-agent: *\nDisallow:\n", 200),
        '*/api/embed' => Http::response(fakeEmbedResponse(), 200),
        '*' => Http::response($html, 200),
    ]);

    (new CrawlLegalSourcePage($source, 'https://lawphil.net/judjuris/judjuris.html'))
        ->handle(
            app(EmbeddingService::class),
            new CrawlerAdapterFactory,
            new RobotsTxt,
        );

    $page = CrawledPage::where('url', 'https://lawphil.net/judjuris/judjuris.html')->first();

    expect($page)->not->toBeNull()
        ->and($page->crawl_status)->toBe(CrawlStatus::Ok)
        ->and($page->law_name)->toBe('Republic Act No. 6657')
        ->and($page->gr_number)->toBe('G.R. No. 143491')
        ->and($page->promulgation_date->toDateString())->toBe('2003-06-10');

    expect($page->legalChunks()->count())->toBeGreaterThan(0);
});

it('skips re-indexing when the content hash is unchanged', function () {
    Storage::fake('local');

    $source = LegalSource::factory()->create(['base_domain' => 'lawphil.net']);

    $html = '<html><body><p>Republic Act No. 6657 coverage rules.</p></body></html>';

    Http::fake([
        '*/robots.txt' => Http::response("User-agent: *\nDisallow:\n", 200),
        '*/api/embed' => Http::response(fakeEmbedResponse(), 200),
        '*' => Http::response($html, 200),
    ]);

    $job = new CrawlLegalSourcePage($source, 'https://lawphil.net/statutes/repacts/repacts.html');
    $embeddings = app(EmbeddingService::class);
    $adapters = new CrawlerAdapterFactory;
    $robots = new RobotsTxt;

    $job->handle($embeddings, $adapters, $robots);
    $chunksAfterFirst = LegalChunk::count();

    $job->handle($embeddings, $adapters, $robots);

    expect(LegalChunk::count())->toBe($chunksAfterFirst)
        ->and($chunksAfterFirst)->toBeGreaterThan(0);
});

it('records an http error as a failed crawl', function () {
    $source = LegalSource::factory()->create(['base_domain' => 'example.gov.ph']);

    Http::fake([
        '*/robots.txt' => Http::response("User-agent: *\nDisallow:\n", 200),
        '*' => Http::response('Not Found', 404),
    ]);

    (new CrawlLegalSourcePage($source, 'https://example.gov.ph/missing'))
        ->handle(
            app(EmbeddingService::class),
            new CrawlerAdapterFactory,
            new RobotsTxt,
        );

    $this->assertDatabaseHas('crawled_pages', [
        'legal_source_id' => $source->id,
        'crawl_status' => CrawlStatus::Failed->value,
    ]);
});
