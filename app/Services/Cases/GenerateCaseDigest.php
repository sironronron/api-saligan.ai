<?php

namespace App\Services\Cases;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class GenerateCaseDigest implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    /**
     * @var array<int, int>
     */
    public array $backoff = [10, 60, 300];

    public function __construct(public readonly string $caseId)
    {
        // The case is reloaded in handle() so queued work never uses stale data.
    }

    /**
     * Serialize generations for one case while allowing different cases to
     * generate in parallel.
     *
     * @return array<int, WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('case-digest:'.$this->caseId))
                ->releaseAfter(15)
                ->expireAfter(600),
        ];
    }

    public function handle(CaseDigestService $digests): void
    {
        $digests->generateAndStore($this->caseId);
    }
}
