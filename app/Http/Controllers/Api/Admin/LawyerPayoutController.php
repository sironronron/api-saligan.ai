<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\LawyerPayoutResource;
use App\Models\LawyerPayout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LawyerPayoutController extends Controller
{
    /**
     * All generated payouts, filterable by status.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = LawyerPayout::query()->with('lawyer:id,name')->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return LawyerPayoutResource::collection($query->paginate(50));
    }

    /**
     * Mark a payout as disbursed (paid out to the lawyer).
     */
    public function markPaid(Request $request, LawyerPayout $lawyerPayout): JsonResponse
    {
        $validated = $request->validate([
            'payout_ref' => ['nullable', 'string', 'max:100'],
        ]);

        abort_if($lawyerPayout->status === LawyerPayout::STATUS_PAID, 422, 'This payout is already paid.');

        $lawyerPayout->update([
            'status' => LawyerPayout::STATUS_PAID,
            'payout_ref' => $validated['payout_ref'] ?? null,
            'paid_at' => now(),
        ]);

        return response()->json([
            'data' => (new LawyerPayoutResource($lawyerPayout->load('lawyer:id,name')))->resolve(),
        ]);
    }
}
