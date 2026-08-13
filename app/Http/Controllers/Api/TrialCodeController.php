<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\TrialRedemptionException;
use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriptionResource;
use App\Models\TrialCode;
use App\Services\Billing\TrialRedeemer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TrialCodeController extends Controller
{
    public function __construct(private readonly TrialRedeemer $redeemer)
    {
        //
    }

    /**
     * Check a code without claiming it, so the form can confirm what the code
     * grants before the user commits.
     *
     * Deliberately vague on failure: a precise reason here would let anyone
     * enumerate valid codes. The redeem call gives the specific reason, and by
     * then the caller has committed to a code they already hold.
     */
    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64'],
        ]);

        $code = TrialCode::findByCode($validated['code']);

        if ($code === null || ! $code->isRedeemable()) {
            return response()->json(['valid' => false, 'message' => 'That code is not valid.'], 404);
        }

        $plan = $code->plan;

        return response()->json([
            'valid' => true,
            'trial_days' => $code->trial_days,
            'plan' => $plan?->name,
            'referred_by' => $code->owner?->name,
        ]);
    }

    /**
     * Redeem a code, starting the organization's free trial.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64'],
        ]);

        try {
            $subscription = $this->redeemer->redeem($request->user(), $validated['code']);
        } catch (TrialRedemptionException $e) {
            // 422 rather than 403: the request is well-formed, the code or the
            // account simply is not eligible, and the message is user-facing.
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => new SubscriptionResource($subscription->load('plan')),
        ], 201);
    }
}
