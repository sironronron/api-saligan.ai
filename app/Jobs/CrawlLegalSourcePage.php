<?php

namespace App\Jobs;

use App\Enums\CrawlStatus;
use App\Models\CrawledPage;
use App\Models\LegalChunk;
use App\Models\LegalSource;
use App\Services\Ai\EmbeddingService;
use App\Services\Crawler\CrawlerAdapterFactory;
use App\Services\Crawler\LegalDigestService;
use App\Services\Crawler\PdfAdapter;
use App\Services\Crawler\RobotsTxt;
use App\Services\Documents\DocumentChunker;
use App\Support\CacheLock;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class CrawlLegalSourcePage implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 180;

    /**
     * @return array<int, int>
     */
    public array $backoff = [30, 120, 600];

    public function __construct(
        public readonly LegalSource $source,
        public readonly string $url,
        public readonly int $depth = 0,
    ) {
        $this->onQueue(config('saligan.crawler.queue'));
    }

    public function uniqueId(): string
    {
        return $this->url;
    }

    public function uniqueFor(): int
    {
        return 3600;
    }

    public function handle(EmbeddingService $embeddings, CrawlerAdapterFactory $adapters, RobotsTxt $robots): void
    {
        if (! config('saligan.crawler.enabled') || ! $this->source->is_active) {
            return;
        }

        if ($this->depth > (int) config('saligan.crawler.max_depth', 2)) {
            return;
        }

        if (! $robots->allows($this->url)) {
            $page = CrawledPage::firstOrCreate(
                ['legal_source_id' => $this->source->id, 'url' => $this->url],
                ['crawl_status' => CrawlStatus::Pending->value],
            );

            $this->recordFailure('Blocked by robots.txt', $page);

            return;
        }

        $page = CrawledPage::firstOrCreate(
            ['legal_source_id' => $this->source->id, 'url' => $this->url],
            ['crawl_status' => CrawlStatus::Pending->value],
        );

        if ($page->crawl_status === CrawlStatus::Pending && $page->raw_html_path !== null) {
            return;
        }

        try {
            $response = Http::timeout(60)
                ->withHeaders(['User-Agent' => config('saligan.crawler.user_agent')])
                ->get($this->url);

            if (! $response->ok()) {
                $this->recordFailure("HTTP {$response->status()}", $page);

                return;
            }

            $body = $response->body();
            $hash = hash('sha256', $body);

            if ($hash === $page->content_hash && $page->crawl_status === CrawlStatus::Ok) {
                $page->update(['last_crawled_at' => now()]);

                return;
            }

            $parsed = $this->isPdf($this->url, $body, (string) $response->header('Content-Type', ''))
                ? (new PdfAdapter)->parse($body, $this->url)
                : $adapters->resolve($this->source->base_domain)->parse($body, $this->url);

            $this->storeRawArtifact($page, $body);

            $page->update([
                'content_hash' => $hash,
                'title' => $parsed->title,
                'law_name' => $parsed->lawName,
                'gr_number' => $parsed->grNumber,
                'promulgation_date' => $parsed->promulgationDate,
                'crawl_status' => CrawlStatus::Ok->value,
                'last_error' => null,
                'last_crawled_at' => now(),
            ]);

            $this->reindexChunks($page, $parsed->text, $embeddings);

            // Written after the text is safely stored: a digest is a
            // convenience on top of the authority, never a precondition for
            // having it.
            $this->writeDigest($page, $parsed->text);

            $this->discoverLinks($parsed->links);

            $this->throttle();
        } catch (Throwable $exception) {
            Log::error('Crawl failed', [
                'url' => $this->url,
                'exception' => $exception->getMessage(),
            ]);

            $this->recordFailure(Str::limit($exception->getMessage(), 500), $page);

            if ($this->attempts() >= $this->tries) {
                $this->fail($exception);
            }
        }
    }

    /**
     * Generate and store the reader digest for a freshly crawled page.
     * Failures are swallowed by the service, so this never costs the crawl.
     */
    private function writeDigest(CrawledPage $page, string $text): void
    {
        $digest = app(LegalDigestService::class)->generate($text, $page->title);

        if ($digest === null) {
            return;
        }

        $page->update([
            'digest' => $digest,
            'digest_generated_at' => now(),
        ]);
    }

    private function reindexChunks(CrawledPage $page, string $text, EmbeddingService $embeddings): void
    {
        $chunks = (new DocumentChunker)->chunk($text);

        if ($chunks === []) {
            return;
        }

        $vectors = $embeddings->embedMany($chunks);

        $page->legalChunks()->delete();

        foreach ($chunks as $index => $content) {
            LegalChunk::create([
                'crawled_page_id' => $page->id,
                'chunk_index' => $index,
                'content' => $content,
                'embedding' => $vectors[$index],
            ]);
        }
    }

    /**
     * @param  string[]  $links
     */
    private function discoverLinks(array $links): void
    {
        if ($links === []) {
            return;
        }

        $baseDomain = $this->source->base_domain;
        $queued = 0;
        $maxLinks = (int) config('saligan.crawler.max_links_per_page', 25);

        foreach ($links as $link) {
            if ($queued >= $maxLinks) {
                break;
            }

            $absolute = $this->absoluteUrl($this->url, $link);

            if (! $this->isSameDomain($absolute, $baseDomain)) {
                continue;
            }

            $hash = hash('sha256', $absolute);

            if (CacheLock::acquire("crawler:seen:{$hash}")) {
                CrawlLegalSourcePage::dispatch($this->source, $absolute, $this->depth + 1);
                $queued++;
            }
        }
    }

    private function absoluteUrl(string $base, string $link): string
    {
        $link = trim($link);

        if (str_starts_with($link, '#')) {
            return '';
        }

        if (filter_var($link, FILTER_VALIDATE_URL) !== false) {
            return $link;
        }

        $parsed = parse_url($base);

        if (str_starts_with($link, '//')) {
            return ($parsed['scheme'] ?? 'https').':'.$link;
        }

        if (str_starts_with($link, '/')) {
            return ($parsed['scheme'] ?? 'https').'://'.($parsed['host'] ?? '').$link;
        }

        $path = $parsed['path'] ?? '';
        $dir = preg_replace('#/[^/]*$#', '', $path) ?? '';

        return ($parsed['scheme'] ?? 'https').'://'.($parsed['host'] ?? '').'/'.ltrim($dir, '/').'/'.$link;
    }

    private function isSameDomain(string $url, string $baseDomain): bool
    {
        if ($url === '') {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);

        if ($host === null || $host === false) {
            return false;
        }

        return $host === $baseDomain || str_ends_with($host, '.'.$baseDomain);
    }

    private function storeRawArtifact(CrawledPage $page, string $body): void
    {
        // LawPhil and the Gazette publish authorities as PDFs in addition to
        // HTML; store whatever came back under a path that reflects it so the
        // artifact can be inspected or re-processed later.
        $extension = $this->isPdf($page->url ?? '', $body) ? 'pdf' : 'html';
        $path = 'crawled-pages/'.$page->id.'.'.$extension;

        Storage::disk('local')->put($path, $body);

        $page->update(['raw_html_path' => $path]);
    }

    /**
     * Whether a fetched body is a PDF rather than HTML, judged by the URL's
     * file extension, the response Content-Type, or the magic bytes.
     */
    private function isPdf(string $url, string $body, string $contentType = ''): bool
    {
        if (str_contains(strtolower($contentType), 'application/pdf')) {
            return true;
        }

        if (str_ends_with(strtolower((string) parse_url($url, PHP_URL_PATH)), '.pdf')) {
            return true;
        }

        return str_starts_with($body, '%PDF-');
    }

    private function throttle(): void
    {
        $delay = (int) config('saligan.crawler.delay_ms', 3000) / 1000;

        if ($delay > 0) {
            usleep((int) ($delay * 1_000_000));
        }
    }

    private function recordFailure(string $reason, ?CrawledPage $page = null): void
    {
        $page?->update([
            'crawl_status' => CrawlStatus::Failed->value,
            'last_error' => Str::limit($reason, 500),
            'last_crawled_at' => now(),
        ]);
    }
}
