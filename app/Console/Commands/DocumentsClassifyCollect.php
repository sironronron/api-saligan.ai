<?php

namespace App\Console\Commands;

use App\Services\Documents\DocumentClassificationBatcher;
use Illuminate\Console\Command;

class DocumentsClassifyCollect extends Command
{
    protected $signature = 'documents:classify-collect';

    protected $description = 'File the documents whose classification batches have ended';

    public function handle(DocumentClassificationBatcher $batcher): int
    {
        $closed = $batcher->collect();

        $this->info("Classification requests closed: {$closed}");

        return self::SUCCESS;
    }
}
