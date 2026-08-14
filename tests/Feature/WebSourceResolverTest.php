<?php

use App\Support\WebSourceResolver;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('saligan.web_search.resolve_sources', true);
    config()->set('saligan.crawler.block_private_addresses', false);
});

it('identifies a grounding redirect by the page it leads to', function () {
    Http::fake([
        'vertexaisearch.cloud.google.com/*' => Http::response(
            '<html><head><title>G.R. No. 186204 - Spouses Javier v. Spouses De Guzman</title></head><body>…</body></html>',
        ),
    ]);

    $resolved = WebSourceResolver::resolve([
        ['url' => 'https://vertexaisearch.cloud.google.com/grounding-api-redirect/AUZIYQ', 'title' => 'judiciary.gov.ph'],
    ]);

    expect($resolved[0]['title'])->toBe('G.R. No. 186204 - Spouses Javier v. Spouses De Guzman');
});

it('names a source the search reported without a title', function () {
    Http::fake([
        'elibrary.judiciary.gov.ph/*' => Http::response(
            '<html><head><title>Depra v. Dumlao, G.R. No. L-57348</title></head></html>',
        ),
    ]);

    $resolved = WebSourceResolver::resolve([
        ['url' => 'https://elibrary.judiciary.gov.ph/thebookshelf/showdocs/1/65692', 'title' => null],
    ]);

    expect($resolved[0]['title'])->toBe('Depra v. Dumlao, G.R. No. L-57348')
        ->and($resolved[0]['url'])->toBe('https://elibrary.judiciary.gov.ph/thebookshelf/showdocs/1/65692');
});

it('leaves a source that already names itself alone', function () {
    Http::fake();

    $citations = [[
        'url' => 'https://lawphil.net/judjuris/juri1985/may1985/gr_l57348_1985.html',
        'title' => 'Depra vs. Dumlao : L-57348 : May 16, 1985 : J. Melencio-Herrera',
    ]];

    expect(WebSourceResolver::resolve($citations))->toBe($citations);

    Http::assertNothingSent();
});

it('keeps what the search gave when the page cannot be read', function () {
    Http::fake(['*' => Http::response('nope', 503)]);

    $citations = [['url' => 'https://vertexaisearch.cloud.google.com/grounding-api-redirect/AUZIYQ', 'title' => 'lawphil.net']];

    expect(WebSourceResolver::resolve($citations))->toBe($citations);
});

it('keeps what the search gave when the page carries no title', function () {
    Http::fake(['*' => Http::response('<html><body>Just a body.</body></html>')]);

    $citations = [['url' => 'https://lawphil.net/some-page', 'title' => null]];

    expect(WebSourceResolver::resolve($citations))->toBe($citations);
});

it('does not fetch anything when resolution is switched off', function () {
    config()->set('saligan.web_search.resolve_sources', false);

    Http::fake();

    $citations = [['url' => 'https://vertexaisearch.cloud.google.com/grounding-api-redirect/AUZIYQ', 'title' => 'lawphil.net']];

    expect(WebSourceResolver::resolve($citations))->toBe($citations);

    Http::assertNothingSent();
});

it('refuses to fetch a source that is not a public address', function () {
    config()->set('saligan.crawler.block_private_addresses', true);

    Http::fake();

    $citations = [['url' => 'http://169.254.169.254/latest/meta-data/', 'title' => null]];

    expect(WebSourceResolver::resolve($citations))->toBe($citations);

    Http::assertNothingSent();
});

it('preserves the snippet a search reported alongside the resolved identity', function () {
    Http::fake(['*' => Http::response('<html><head><title>Real Page</title></head></html>')]);

    $resolved = WebSourceResolver::resolve([
        ['url' => 'https://vertexaisearch.cloud.google.com/grounding-api-redirect/AUZIYQ', 'title' => null, 'snippet' => 'Article 448 applies.'],
    ]);

    expect($resolved[0]['title'])->toBe('Real Page')
        ->and($resolved[0]['snippet'])->toBe('Article 448 applies.');
});
