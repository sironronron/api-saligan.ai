<?php

namespace App\Jobs;

use App\Models\VettingRequest;
use App\Services\Vetting\VettingRequestService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class MatchVettingRequest implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public readonly VettingRequest $vettingRequest)
    {
        //
    }

    /**
     * Guard against two workers notifying lawyers for the same request.
     *
     * @return array<int, WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('vetting-match:'.$this->vettingRequest->id))
                ->releaseAfter(60)
                ->expireAfter(600),
        ];
    }

    /**
     * Offer the request to the next pool of eligible lawyers.
     */
    public function handle(VettingRequestService $service): void
    {
        $service->notifyNextPool($this->vettingRequest);
    }
}
