<?php

namespace App\Console\Commands;

use App\Models\LegalCase;
use Illuminate\Console\Command;

class ArchiveClosedCases extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cases:archive-closed';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Archive closed cases 30 days after they were closed';

    /**
     * The number of days a case stays accessible after closing before it is
     * moved to the archive.
     */
    public const ARCHIVE_AFTER_DAYS = 30;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $cutoff = now()->subDays(self::ARCHIVE_AFTER_DAYS);

        $count = LegalCase::query()
            ->where('status', 'closed')
            ->whereNull('archived_at')
            ->where('closed_at', '<=', $cutoff)
            ->update(['archived_at' => now()]);

        $this->info("Closed cases archived: {$count}");

        return self::SUCCESS;
    }
}
