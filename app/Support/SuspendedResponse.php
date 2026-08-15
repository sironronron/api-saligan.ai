<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * The 403 returned to a member whose organization access has been suspended.
 *
 * It is deliberately not the 402 {@see UpgradeResponse} carries: a suspended
 * member is not short of a subscription — their organization's plan is
 * current, and sending them to a price list would invite them to buy access
 * their admin just took away. The `suspended` flag lets the client route them
 * to the suspension notice instead.
 */
final class SuspendedResponse
{
    public const MESSAGE = 'Your access to this organization has been suspended by an administrator.';

    public static function make(string $message = self::MESSAGE): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'suspended' => true,
        ], 403);
    }
}
