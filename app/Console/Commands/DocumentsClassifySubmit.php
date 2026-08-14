<?php

namespace App\Console\Commands;

use App\Services\Documents\DocumentClassificationBatcher;
use Illuminate\Console\Command;

class DocumentsClassifySubmit extends Command
{
    protected $signature = 'documents:classify-submit
        {--limit= : Maximum documents to send in this batch}';

    protected $description = 'Send the documents waiting to be filed as one classification batch';

    public function handle(DocumentClassificationBatcher $batcher): int
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
