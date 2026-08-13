<?php

namespace App\Http\Controllers\Api;

use App\Enums\CrawlStatus;
use App\Http\Controllers\Controller;
use App\Models\CrawledPage;
use App\Services\Crawler\LegalDigestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class LegalPageController extends Controller
{
    /**
     * A crawled authority, in full, for reading inside the app.
     *
     * The knowledge base is shared public law — statutes, issuances, and
     * decisions — so any authenticated user may read any page. Nothing
     * user-owned is exposed here; uploaded documents are served by
     * DocumentController, which scopes to the owner.
     *
     * The page's chunks double as its paragraphs: they are already the units
     * retrieval cites, so returning them with their indices lets the reader
     * highlight exactly the passage an answer relied on rather than
     * approximating it with a text search.
     */
    public function show(Request $request, CrawledPage $crawledPage): JsonResponse
    {
        $chunks = $crawledPage->chunks()
            ->orderBy('chunk_index')
            ->get(['id', 'chunk_index', 'content']);

        $this->ensureDigest($crawledPage, $chunks->pluck('content')->implode("\n\n"));

        return response()->json([
            'data' => [
                'id' => $crawledPage->id,
                'title' => $crawledPage->title,
                'law_name' => $crawledPage->law_name,
                'gr_number' => $crawledPage->gr_number,
                'promulgation_date' => $crawledPage->promulgation_date?->toDateString(),
                'url' => $crawledPage->url,
                'source_name' => $crawledPage->legalSource?->name,
                'digest' => $crawledPage->digest,
                'last_crawled_at' => $crawledPage->last_crawled_at,
                // Absent when the page was crawled before digests existed, or
                // when the text was too fragmentary to digest. The reader shows
                // the full text either way.
                'has_digest' => filled($crawledPage->digest),
                'chunks' => $chunks->map(fn ($chunk) => [
                    'id' => $chunk->id,
                    'index' => $chunk->chunk_index,
                    'content' => $chunk->content,
                ]),
            ],
        ]);
    }

    /**
     * Digest a page the first time someone reads it.
     *
     * Digesting the whole corpus up front would be one model call per document
     * across the entire knowledge base, most of which nobody ever opens. Doing
     * it on first read spreads the cost over actual demand and leaves popular
     * authorities digested; the `saligan:digest` command exists for deliberate
     * bulk runs.
     *
     * The lock stops several readers opening the same case at once from each
     * paying for the same digest.
     */
    protected function ensureDigest(CrawledPage $page, string $text): void
    {
        if (filled($page->digest) || trim($text) === '') {
            return;
        }

        $lock = Cache::lock('legal-digest:'.$page->id, 60);

        if (! $lock->get()) {
            return;
        }

        try {
            $digest = app(LegalDigestService::class)->generate($text, $page->title);

            if ($digest !== null) {
                $page->forceFill([
                    'digest' => $digest,
                    'digest_generated_at' => now(),
                ])->save();
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * Resolve an external legal URL to a page already in the knowledge base.
     *
     * Answers grounded in a web result link out to the source. When that same
     * authority has been crawled — often it has, since the competition and we
     * both cite the same official corpus — the reader can serve it in-app
     * instead, with the digest and highlighting that the raw site cannot offer.
     */
    public function resolve(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'string', 'max:2048'],
        ]);

        $page = CrawledPage::query()
            ->where('crawl_status', CrawlStatus::Ok->value)
            ->whereIn('url', $this->urlVariants($validated['url']))
            ->first();

        return response()->json([
            'data' => $page === null ? null : [
                'id' => $page->id,
                'title' => $page->title,
                'has_digest' => filled($page->digest),
            ],
        ]);
    }

    /**
     * The forms the same document may have been stored under. Matching is
     * exact on purpose — a fuzzy match risks opening the wrong decision, which
     * is worse than sending the reader to the original site.
     *
     * @return array<int, string>
     */
    protected function urlVariants(string $url): array
    {
        $url = trim($url);
        $withoutSlash = rtrim($url, '/');

        return array_values(array_unique([
            $url,
            $withoutSlash,
            $withoutSlash.'/',
            str_replace('http://', 'https://', $withoutSlash),
            str_replace('https://', 'http://', $withoutSlash),
        ]));
    }
}
