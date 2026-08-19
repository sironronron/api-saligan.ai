<?php

namespace App\Console\Commands;

use App\Models\CrawledPage;
use App\Models\Document;
use Illuminate\Console\Command;

/**
 * Clear stored digests so they are written again against the current
 * specification.
 *
 * A digest is generated once and kept, which is what keeps opening a source
 * fast — but it also means a change to how digests are written reaches nobody
 * until the stored ones are cleared. Clearing is all this does: the next
 * reader of a source regenerates it, so the cost is spent only on sources
 * somebody actually opens, exactly as first-read generation intends.
 */
class DigestReset extends Command
{
    protected $signature = 'saligan:digest-reset
        {--before= : Only clear digests generated before this date (Y-m-d)}
        {--pages : Clear crawled authorities only}
        {--documents : Clear uploaded documents only}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Clear stored digests so they are regenerated on next read';

    public function handle(): int
    {
        $before = $this->option('before');

        // Neither flag means both, which is what a bare invocation should do.
        $pages = $this->option('pages') || ! $this->option('documents');
        $documents = $this->option('documents') || ! $this->option('pages');

        $pageQuery = CrawledPage::query()->whereNotNull('digest');
        $documentQuery = Document::query()->whereNotNull('digest');

        if ($before !== null) {
            $pageQuery->where('digest_generated_at', '<', $before);
            $documentQuery->where('digest_generated_at', '<', $before);
        }

        $pageCount = $pages ? (clone $pageQuery)->count() : 0;
        $documentCount = $documents ? (clone $documentQuery)->count() : 0;

        if ($pageCount + $documentCount === 0) {
            $this->info('No stored digests to clear.');

            return self::SUCCESS;
        }

        $this->line("Clearing {$pageCount} authority digest(s) and {$documentCount} document digest(s).");

        if (! $this->option('force') && ! $this->confirm('Each will be written again the next time someone opens the source. Continue?', true)) {
            return self::SUCCESS;
        }

        if ($pages) {
            $pageQuery->update(['digest' => null, 'digest_generated_at' => null]);
        }

        if ($documents) {
            $documentQuery->update(['digest' => null, 'digest_generated_at' => null]);
        }

        $this->info('Done. Digests will be regenerated on next read.');

        return self::SUCCESS;
    }
}
