<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;

class TourController extends Controller
{
    /**
     * Record that the user has finished or skipped the product tour.
     *
     * Idempotent: the first completion is the one that counts, so replaying the
     * tour from the account menu does not keep moving the timestamp forward.
     */
    public function complete(Request $request): UserResource
    {
        $user = $request->user();

        if ($user->tour_completed_at === null) {
            $user->forceFill(['tour_completed_at' => now()])->save();
        }

        return new UserResource($user);
    }
}
