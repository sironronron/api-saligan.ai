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
    /**
     * @param  array<string, mixed>  $meta  Extra keys the client branches on
     *                                      (usage percent, reset date). The
     *                                      shape stays backward compatible:
     *                                      `message` plus `upgrade_required`.
     */
    public static function make(string $message, array $meta = []): JsonResponse
    {
        return response()->json(array_merge([
            'message' => $message,
            'upgrade_required' => true,
        ], $meta), 402);
    }
}
