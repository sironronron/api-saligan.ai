<?php

namespace App\Console\Commands;

use App\Services\Crawler\LegalDigestBatcher;
use Illuminate\Console\Command;

class DigestSubmit extends Command
{
    protected $signature = 'saligan:digest-submit
        {--limit= : Maximum pages to send in this batch}';

    protected $description = 'Send the crawled authorities waiting on a digest as one batch';

    public function handle(LegalDigestBatcher $batcher): int
    {
        $limit = $this->option('limit');

        $batchId = $batcher->submit($limit === null ? null : (int) $limit);

        if ($batchId === null) {
            $this->info('Nothing to submit.');

            return self::SUCCESS;
        }

        $this->info("Submitted batch {$batchId}.");

        return self::SUCCESS;
    }
}
