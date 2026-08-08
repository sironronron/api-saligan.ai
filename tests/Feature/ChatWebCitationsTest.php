<?php

use App\Services\Chat\ChatService;
use Illuminate\Support\Collection;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\UrlCitation;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Laravel\Ai\Streaming\Events\Citation;
use Laravel\Ai\Streaming\Events\ProviderToolEvent;

beforeEach(function () {
    $this->chat = new class extends ChatService
    {
        public function __construct() {}

        public function extractWebCitations(StreamedAgentResponse $response): array
        {
            return $this->webCitations($response);
        }
    };
});

it('captures web citations from provider grounding metadata', function () {
    $response = new StreamedAgentResponse(
        'invocation',
        new Collection,
        new Meta('gemini', 'model', new Collection([
            new UrlCitation('https://lawphil.net/ra-6657', 'RA 6657'),
        ])),
    );

    expect($this->chat->extractWebCitations($response))->toBe([
        ['url' => 'https://lawphil.net/ra-6657', 'title' => 'RA 6657'],
    ]);
});

it('captures web citations from Anthropic citation events', function () {
    $response = new StreamedAgentResponse('invocation', new Collection([
        new Citation(
            'event-id',
            'message-id',
            new UrlCitation('https://sc.judiciary.gov.ph/rule-43', 'SC E-Library — Rule 43'),
            time(),
        ),
    ]), new Meta('anthropic', 'model'));

    expect($this->chat->extractWebCitations($response))->toBe([
        ['url' => 'https://sc.judiciary.gov.ph/rule-43', 'title' => 'SC E-Library — Rule 43'],
    ]);
});

it('captures web citations from Anthropic web search tool results', function () {
    $response = new StreamedAgentResponse('invocation', new Collection([
        new ProviderToolEvent(
            'event-id',
            'tool-use-id',
            'web_search_tool_result',
            [
                'search_results' => [
                    [
                        'url' => 'https://officialgazette.gov.ph/ra-6657',
                        'title' => 'Official Gazette — RA 6657',
                        'snippet' => 'The Comprehensive Agrarian Reform Law covers all public agricultural lands.',
                    ],
                ],
            ],
            'result_received',
            time(),
        ),
    ]), new Meta('anthropic', 'model'));

    expect($this->chat->extractWebCitations($response))->toBe([
        [
            'url' => 'https://officialgazette.gov.ph/ra-6657',
            'title' => 'Official Gazette — RA 6657',
            'snippet' => 'The Comprehensive Agrarian Reform Law covers all public agricultural lands.',
        ],
    ]);
});

it('deduplicates web citations by url and merges the cited excerpt', function () {
    $response = new StreamedAgentResponse('invocation', new Collection([
        new Citation(
            'event-id',
            'message-id',
            new UrlCitation('https://lawphil.net/ra-6657', 'LawPhil'),
            time(),
        ),
        new ProviderToolEvent(
            'event-id-2',
            'tool-use-id',
            'web_search_tool_result',
            [
                'search_results' => [
                    ['url' => 'https://lawphil.net/ra-6657', 'title' => 'LawPhil', 'snippet' => 'First result.'],
                    ['url' => 'https://sc.judiciary.gov.ph/rule-43', 'title' => 'SC E-Library', 'snippet' => 'Second result.'],
                ],
            ],
            'result_received',
            time(),
        ),
    ]), new Meta('anthropic', 'model'));

    $citations = $this->chat->extractWebCitations($response);

    expect($citations)->toHaveCount(2)
        ->and($citations[0]['url'])->toBe('https://lawphil.net/ra-6657')
        ->and($citations[0]['title'])->toBe('LawPhil')
        ->and($citations[0]['snippet'])->toBe('First result.')
        ->and($citations[1]['url'])->toBe('https://sc.judiciary.gov.ph/rule-43')
        ->and($citations[1]['snippet'])->toBe('Second result.');
});

it('ignores web citations without a valid url', function () {
    $response = new StreamedAgentResponse('invocation', new Collection([
        new Citation('event-id', 'message-id', new UrlCitation(''), time()),
        new ProviderToolEvent(
            'event-id-2',
            'tool-use-id',
            'web_search_tool_result',
            ['search_results' => [['url' => null, 'title' => 'No url']]],
            'result_received',
            time(),
        ),
    ]), new Meta('anthropic', 'model'));

    expect($this->chat->extractWebCitations($response))->toBe([]);
});
