<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlanResource;
use App\Models\Plan;
use App\Support\PlanFeatures;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PlanController extends Controller
{
    /**
     * List the active billing plans, ordered for display, with the feature
     * catalogue that gives their feature keys their labels.
     *
     * The catalogue ships with the plans rather than being duplicated in each
     * client because it was duplicated in each client, and the copies had
     * already drifted: the app's pricing page and the marketing table
     * disagreed about what Starter included. One list, sent from the place
     * that enforces it.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $plans = Plan::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        return PlanResource::collection($plans)->additional([
            'meta' => [
                'features' => PlanFeatures::catalogue(),
            ],
        ]);
    }
}
