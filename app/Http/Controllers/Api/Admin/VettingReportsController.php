<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\VettingPaymentStatus;
use App\Enums\VettingRequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\LawyerProfileResource;
use App\Models\LawyerProfile;
use App\Models\VettingPayment;
use App\Models\VettingRequest;
use App\Services\Vetting\VettingSettings;
use Illuminate\Http\JsonResponse;

/**
 * Admin reporting on the vetting marketplace: volumes, acceptance rates,
 * turnaround, notarization revenue, and workload distribution.
 */
class VettingReportsController extends Controller
{
    /**
     * Platform-wide summary metrics.
     */
    public function summary(): JsonResponse
    {
        $avgTurnoverHours = VettingRequest::query()
            ->where('status', VettingRequestStatus::Completed)
            ->whereNotNull('completed_at')
            ->get(['created_at', 'completed_at'])
            ->avg(fn (VettingRequest $request): float => $request->created_at->diffInHours($request->completed_at));

        $accepted = VettingRequest::query()
            ->whereIn('status', [
                VettingRequestStatus::Accepted,
                VettingRequestStatus::UnderReview,
                VettingRequestStatus::Vetted,
                VettingRequestStatus::Notarized,
                VettingRequestStatus::Completed,
            ])
            ->count();

        $capturedNotarizations = VettingPayment::query()
            ->where('kind', VettingPayment::KIND_NOTARIZATION)
            ->where('status', VettingPaymentStatus::Captured);

        $refundedNotarizations = VettingPayment::query()
            ->where('kind', VettingPayment::KIND_NOTARIZATION)
            ->where('status', VettingPaymentStatus::Refunded);

        $total = VettingRequest::count();

        return response()->json([
            'data' => [
                'requests' => [
                    'total' => $total,
                    'open' => VettingRequest::where('status', VettingRequestStatus::Matched)->count(),
                    'in_progress' => VettingRequest::whereIn('status', [
                        VettingRequestStatus::Accepted,
                        VettingRequestStatus::UnderReview,
                        VettingRequestStatus::Vetted,
                        VettingRequestStatus::Notarized,
                    ])->count(),
                    'completed' => VettingRequest::where('status', VettingRequestStatus::Completed)->count(),
                    'cancelled' => VettingRequest::where('status', VettingRequestStatus::Cancelled)->count(),
                    'waiting' => VettingRequest::where('status', VettingRequestStatus::Waiting)->count(),
                    'declined' => VettingRequest::where('status', VettingRequestStatus::Declined)->count(),
                ],
                'acceptance_rate' => $total > 0
                    ? round($accepted / $total * 100, 1)
                    : 0.0,
                'avg_turnaround_hours' => $avgTurnoverHours !== null
                    ? round((float) $avgTurnoverHours, 1)
                    : null,
                'revenue' => [
                    'notarization' => (int) $capturedNotarizations->sum('amount'),
                    'notarization_refunded' => (int) $refundedNotarizations->sum('amount'),
                    'vetting' => (int) VettingPayment::query()
                        ->where('kind', VettingPayment::KIND_VETTING)
                        ->where('status', VettingPaymentStatus::Captured)
                        ->sum('amount'),
                ],
                'notarization_count' => $capturedNotarizations->count(),
            ],
        ]);
    }

    /**
     * Per-lawyer workload and earnings metrics.
     */
    public function lawyers(): JsonResponse
    {
        $profiles = LawyerProfile::query()
            ->with('user:id,name,email')
            ->latest()
            ->get()
            ->map(function (LawyerProfile $profile): array {
                $assigned = VettingRequest::where('assigned_lawyer_id', $profile->user_id);

                $capturedPayments = VettingPayment::query()
                    ->where('lawyer_id', $profile->user_id)
                    ->where('status', VettingPaymentStatus::Captured)
                    ->get();

                $profileResource = (new LawyerProfileResource($profile))->resolve();

                return [
                    'profile' => $profileResource,
                    'active_requests' => $profile->activeRequests()->count(),
                    'accepted_total' => $assigned->count(),
                    'completed_total' => (clone $assigned)
                        ->where('status', VettingRequestStatus::Completed)
                        ->count(),
                    'notarization_count' => $capturedPayments
                        ->where('kind', VettingPayment::KIND_NOTARIZATION)
                        ->count(),
                    'revenue' => (int) $capturedPayments->sum('amount'),
                    'platform_fee' => (int) $capturedPayments->sum(
                        fn (VettingPayment $payment): int => (int) (($payment->amount * $this->commissionPercent()) / 100),
                    ),
                    'lawyer_share' => (int) $capturedPayments->sum(
                        fn (VettingPayment $payment): int => (int) ($payment->amount - (($payment->amount * $this->commissionPercent()) / 100)),
                    ),
                ];
            })
            ->values();

        return response()->json(['data' => $profiles]);
    }

    protected function commissionPercent(): float
    {
        return app(VettingSettings::class)->commissionPercent();
    }
}
