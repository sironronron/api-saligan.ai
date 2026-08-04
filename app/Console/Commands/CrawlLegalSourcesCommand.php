<?php

namespace App\Console\Commands;

use App\Jobs\CrawlLegalSourcePage;
use App\Models\LegalSource;
use Illuminate\Console\Command;

class CrawlLegalSourcesCommand extends Command
{
    protected $signature = 'crawl:legal-sources {--source= : Only crawl the legal source with this UUID}';

    protected $description = 'Dispatch crawl jobs for the seed URLs of all active legal sources';

    public function handle(): int
    {
        if (! config('saligan.crawler.enabled')) {
            $this->warn('Legal source crawler is disabled (LEGAL_CRAWLER_ENABLED=false).');

            return self::FAILURE;
        }

        $query = LegalSource::query()->where('is_active', true);

        if ($sourceId = $this->option('source')) {
            $query->whereKey($sourceId);
        }

        $sources = $query->get();

        if ($sources->isEmpty()) {
            $this->warn('No active legal sources found.');

            return self::FAILURE;
        }

        $dispatched = 0;

        foreach ($sources as $source) {
            foreach ($source->seed_urls as $url) {
                CrawlLegalSourcePage::dispatch($source, $url);
                $dispatched++;
            }
        }

        $this->info("Dispatched {$dispatched} crawl job(s) for {$sources->count()} source(s).");

        return self::SUCCESS;
    }
}
