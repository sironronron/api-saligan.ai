<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Support\UserProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KycController extends Controller
{
    /**
     * The current user's onboarding profile and the selectable options.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => [
                'kyc_role' => $user->kyc_role,
                'kyc_role_other' => $user->kyc_role_other,
                'kyc_use_case' => $user->kyc_use_case,
                'kyc_use_case_other' => $user->kyc_use_case_other,
                'kyc_document_types' => $user->kyc_document_types,
                'kyc_experience_level' => $user->kyc_experience_level,
                'kyc_completed_at' => $user->kyc_completed_at,
            ],
            'meta' => [
                'role_options' => UserProfile::roleOptions(),
                'use_case_options' => UserProfile::useCaseOptions(),
                'document_type_options' => UserProfile::documentTypeOptions(),
                'experience_level_options' => UserProfile::experienceLevelOptions(),
            ],
        ]);
    }

    /**
     * Save the user's onboarding profile. The profile is self-reported and
     * editable at any time; kyc_completed_at is set on first completion and
     * never moved backwards, so later edits do not reset it.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate(UserProfile::validationRules());

        $user = $request->user();

        $documentTypes = $validated['kyc_document_types'] ?? null;
        if (is_array($documentTypes)) {
            $documentTypes = implode(',', $documentTypes);
        }

        $user->update([
            'kyc_role' => $validated['kyc_role'],
            'kyc_role_other' => $validated['kyc_role'] === UserProfile::ROLE_OTHER
                ? ($validated['kyc_role_other'] ?? null)
                : null,
            'kyc_use_case' => $validated['kyc_use_case'],
            'kyc_use_case_other' => $validated['kyc_use_case'] === UserProfile::USE_CASE_OTHER
                ? ($validated['kyc_use_case_other'] ?? null)
                : null,
            'kyc_document_types' => $documentTypes,
            'kyc_experience_level' => $validated['kyc_experience_level'] ?? null,
            'kyc_completed_at' => $user->kyc_completed_at ?? now(),
        ]);

        return (new UserResource($user->fresh()))->response();
    }

    /**
     * Clear the user's onboarding profile so no profile-based calibration
     * applies — the account-settings equivalent of skipping onboarding.
     */
    public function destroy(Request $request): JsonResponse
    {
        $request->user()->update([
            'kyc_role' => null,
            'kyc_role_other' => null,
            'kyc_use_case' => null,
            'kyc_use_case_other' => null,
            'kyc_document_types' => null,
            'kyc_experience_level' => null,
            'kyc_completed_at' => null,
        ]);

        return response()->json(null, 204);
    }
}
