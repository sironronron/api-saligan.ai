<?php

namespace App\Support;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Turns the citations a grounded search reports into sources a reader — and
 * the model writing the answer — can actually identify.
 *
 * Google Search grounding does not hand back the page it read. It hands back a
 * redirect through vertexaisearch.cloud.google.com titled with a bare domain
 * ("judiciary.gov.ph"), or, on the citation-metadata path, a real URL with no
 * title at all. Neither says which decision or issuance the source is, which
 * has two consequences: the source card gives the reader nothing to recognize,
 * and the model — told only "judiciary.gov.ph" — attaches the case it is
 * discussing to whichever source it guesses, so an answer about Depra v.
 * Dumlao can end up linking to a later case that merely quotes it.
 *
 * So each source is fetched once, following the redirect to the page itself,
 * and identified by its own title. The stored URL becomes the real one, which
 * also lets the citation-capture job recognize an official host and pull the
 * authority into the knowledge base — a redirect URL never matched its
 * allowlist.
 */
final class WebSourceResolver
{
    /**
     * Hosts that stand in front of the real source. A URL on one of these says
     * nothing about the page, so it is always resolved.
     *
     * @var array<int, string>
     */
    private const REDIRECTORS = [
        'vertexaisearch.cloud.google.com',
        'www.google.com',
        'news.google.com',
        'duckduckgo.com',
    ];

    /**
     * How much of a page is read looking for its title. The title is in the
     * head, so this never needs to be large — and the read happens inside a
     * chat turn, against pages (full Supreme Court decisions) that run to
     * megabytes.
     */
    private const READ_BYTES = 65536;

    /**
     * Resolve each citation to the page it actually points at, with that
     * page's own title. Citations that already identify themselves are left
     * alone, and anything that cannot be fetched keeps what the search gave.
     *
     * @param  array<int, array{url: string, title: string|null, snippet?: string|null}>  $citations
     * @return array<int, array{url: string, title: string|null, snippet?: string|null}>
     */
    public static function resolve(array $citations): array
    {
        if ($citations === [] || ! config('saligan.web_search.resolve_sources', true)) {
            return $citations;
        }

        $pending = [];

        foreach ($citations as $position => $citation) {
            if (self::needsResolving($citation) && OutboundUrl::isFetchable($citation['url'])) {
                $pending[$position] = $citation['url'];
            }
        }

        if ($pending === []) {
            return $citations;
        }

        foreach (self::fetch($pending) as $position => $resolved) {
            $citations[$position] = array_merge($citations[$position], $resolved);
        }

        return $citations;
    }

    /**
     * Whether a citation still needs to be resolved: it points through a
     * redirector, or it does not name itself.
     *
     * @param  array{url: string, title: string|null, snippet?: string|null}  $citation
     */
    protected static function needsResolving(array $citation): bool
    {
        $host = strtolower((string) parse_url($citation['url'], PHP_URL_HOST));

        if ($host === '') {
            return false;
        }

        if (in_array($host, self::REDIRECTORS, true)) {
            return true;
        }

        $title = trim((string) ($citation['title'] ?? ''));

        // A title that is only the site it came from ("lawphil.net") names the
        // publisher, not the authority, and is no more use than none at all.
        return $title === '' || preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $title) === 1;
    }

    /**
     * Fetch the pending urls in parallel and read the page identity off each.
     *
     * @param  array<int, string>  $pending  Urls keyed by their position in the citation list.
     * @return array<int, array{url?: string, title?: string}>
     */
    protected static function fetch(array $pending): array
    {
        $timeout = max(1, (int) config('saligan.web_search.resolve_timeout', 6));

        try {
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn (string $url) => $pool->as((string) $url)
                    ->timeout($timeout)
                    ->withHeaders(['User-Agent' => config('saligan.crawler.user_agent')])
                    // The url comes from a search result, so every hop is
                    // vetted rather than followed on trust: a public page is
                    // free to redirect to an internal address, and following
                    // it is the whole shape of an SSRF.
                    ->withOptions([
                        'stream' => true,
                        'allow_redirects' => [
                            'max' => 5,
                            'strict' => true,
                            'referer' => false,
                            'protocols' => ['http', 'https'],
                            'on_redirect' => function ($request, $response, $uri): void {
                                if (! OutboundUrl::isFetchable((string) $uri)) {
                                    throw new RuntimeException("Refused to follow a redirect to a non-public address: {$uri}");
                                }
                            },
                        ],
                    ])
                    ->get($url),
                array_values($pending),
            ));
        } catch (Throwable) {
            return [];
        }

        $resolved = [];

        foreach ($pending as $position => $url) {
            $response = $responses[$url] ?? null;

            if (! $response instanceof Response || ! $response->successful()) {
                continue;
            }

            $identity = [];

            if (($final = self::finalUrl($response, $url)) !== null) {
                $identity['url'] = $final;
            }

            if (($title = self::title($response)) !== null) {
                $identity['title'] = $title;
            }

            if ($identity !== []) {
                $resolved[$position] = $identity;
            }
        }

        return $resolved;
    }

    /**
     * The url the request actually ended on, when the client reported one and
     * it is a public address.
     */
    protected static function finalUrl(Response $response, string $requested): ?string
    {
        try {
            $effective = (string) $response->effectiveUri();
        } catch (Throwable) {
            return null;
        }

        if ($effective === '' || $effective === $requested) {
            return null;
        }

        return OutboundUrl::isFetchable($effective) ? $effective : null;
    }

    /**
     * The page's own title, read from the first part of the body.
     */
    protected static function title(Response $response): ?string
    {
        try {
            $head = $response->toPsrResponse()->getBody()->read(self::READ_BYTES);
        } catch (Throwable) {
            return null;
        }

        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $head, $matches) !== 1) {
            return null;
        }

        $title = html_entity_decode(strip_tags($matches[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $title = trim((string) preg_replace('/\s+/u', ' ', $title));

        return $title === '' ? null : Str::limit($title, 180);
    }
}
