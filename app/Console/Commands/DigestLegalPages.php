<?php

namespace App\Console\Commands;

use App\Enums\CrawlStatus;
use App\Models\CrawledPage;
use App\Services\Crawler\LegalDigestService;
use Illuminate\Console\Command;

/**
 * Digest crawled authorities in bulk.
 *
 * Pages are normally digested at crawl time, or on first read for anything
 * crawled before digests existed. This command is for deliberate backfills —
 * seeding digests for the authorities you know get cited most, rather than
 * paying for the whole corpus at once.
 */
class DigestLegalPages extends Command
{
    protected $signature = 'saligan:digest
        {--limit=50 : How many pages to digest in this run}
        {--all : Re-digest pages that already have one}';

    protected $description = 'Write plain-language digests for crawled legal authorities';

    public function handle(LegalDigestService $digests): int
    {
        $query = CrawledPage::query()
            ->where('crawl_status', CrawlStatus::Ok->value)
            ->when(! $this->option('all'), fn ($q) => $q->whereNull('digest'))
            // Most-recently crawled first: those are the pages most likely to
            // be cited, and a partial run should cover them before the tail.
            ->orderByDesc('last_crawled_at');

        $limit = max(1, (int) $this->option('limit'));
        $pages = $query->limit($limit)->get();

        if ($pages->isEmpty()) {
            $this->info('Nothing to digest.');

            return self::SUCCESS;
        }

        $this->line("Digesting {$pages->count()} page(s) with ".config('saligan.crawler.digest.provider').'…');

        $written = 0;
        $skipped = 0;

        $bar = $this->output->createProgressBar($pages->count());
        $bar->start();

        foreach ($pages as $page) {
            $text = $page->chunks()->orderBy('chunk_index')->pluck('content')->implode("\n\n");

            $digest = $digests->generate($text, $page->title);

            if ($digest === null) {
                // Fragmentary pages (indexes, navigation) legitimately have no
                // digest; they are counted, not retried.
                $skipped++;
            } else {
                $page->forceFill(['digest' => $digest, 'digest_generated_at' => now()])->save();
                $written++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $remaining = CrawledPage::query()
            ->where('crawl_status', CrawlStatus::Ok->value)
            ->whereNull('digest')
            ->count();

        $this->info("Wrote {$written}, skipped {$skipped}. {$remaining} page(s) still without a digest.");

        return self::SUCCESS;
    }
}
