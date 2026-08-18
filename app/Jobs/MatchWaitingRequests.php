<?php

namespace App\Jobs;

use App\Enums\VettingRequestStatus;
use App\Models\LawyerProfile;
use App\Models\VettingRequest;
use App\Services\Vetting\LawyerMatcher;
use App\Services\Vetting\VettingRequestService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

/**
 * A lawyer just became available, so any request sitting in the waiting lane
 * they could take is re-offered to a fresh pool (the newly online lawyer
 * included). Kept bounded so a single availability toggle cannot fan out into
 * an unbounded flood of notifications.
 */
class MatchWaitingRequests implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public readonly LawyerProfile $lawyerProfile)
    {
        //
    }

    /**
     * @return array<int, WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('vetting-available:'.$this->lawyerProfile->id))
                ->releaseAfter(60)
                ->expireAfter(600),
        ];
    }

    /**
     * Re-run matching for the oldest waiting requests this lawyer can take.
     */
    public function handle(VettingRequestService $service, LawyerMatcher $matcher): void
    {
        $lawyerId = $this->lawyerProfile->user_id;

        VettingRequest::query()
            ->whereIn('status', [VettingRequestStatus::Pending, VettingRequestStatus::Waiting])
            ->whereNull('assigned_lawyer_id')
            ->orderBy('created_at')
            ->limit(20)
            ->get()
            ->each(function (VettingRequest $request) use ($service, $matcher, $lawyerId): void {
                if (! $matcher->candidates($request)->contains('id', $lawyerId)) {
                    return;
                }

                $service->startMatching($request);
            });
    }
}
