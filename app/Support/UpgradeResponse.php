<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * The 402 the frontend keys off to show an upgrade prompt.
 *
 * Every refusal that a different plan would have allowed answers in this one
 * shape — a message written for the reader, plus the `upgrade_required` flag
 * the client branches on. It lives on its own rather than inside
 * {@see PlanLimits} because {@see PlanFeatures} refuses for a different reason
 * and must still be indistinguishable to the client: a capability the plan
 * does not carry and an allowance it has spent are both "upgrade to continue".
 */
final class UpgradeResponse
{
    public static function make(string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'upgrade_required' => true,
        ], 402);
    }
}
