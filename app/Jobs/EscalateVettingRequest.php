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

/**
 * The backstop for a request nobody answered: after the response window has
 * passed, expires the stale offers and offers the request to the next pool of
 * lawyers. Dispatched (delayed by the escalation window) whenever a pool is
 * notified, so each request always has a sweep ahead of it.
 */
class EscalateVettingRequest implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public readonly VettingRequest $vettingRequest)
    {
        //
    }

    /**
     * @return array<int, WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('vetting-escalate:'.$this->vettingRequest->id))
                ->releaseAfter(60)
                ->expireAfter(600),
        ];
    }

    /**
     * Expire unanswered offers and notify the next pool, if any.
     */
    public function handle(VettingRequestService $service): void
    {
        $service->escalate($this->vettingRequest);
    }
}
