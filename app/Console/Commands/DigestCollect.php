<?php

namespace App\Console\Commands;

use App\Services\Crawler\LegalDigestBatcher;
use Illuminate\Console\Command;

class DigestCollect extends Command
{
    protected $signature = 'saligan:digest-collect';

    protected $description = 'Write the digests whose batches have ended';

    public function handle(LegalDigestBatcher $batcher): int
    {
        $closed = $batcher->collect();

        $this->info("Digest requests closed: {$closed}");

        return self::SUCCESS;
    }
}
