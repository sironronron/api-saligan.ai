<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Support\UserProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

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
        // Role and primary use are multi-select, but a single key is still a
        // valid answer — wrapping it keeps older clients working unchanged.
        // Duplicates are dropped before validating so a repeated key cannot eat
        // into the selection cap.
        $request->merge([
            'kyc_role' => self::distinct($request->input('kyc_role')),
            'kyc_use_case' => self::distinct($request->input('kyc_use_case')),
        ]);

        $validated = $request->validate(UserProfile::validationRules(
            $request->input('kyc_role', []),
            $request->input('kyc_use_case', []),
        ));

        $user = $request->user();

        $roles = $validated['kyc_role'];
        $useCases = $validated['kyc_use_case'];

        $documentTypes = $validated['kyc_document_types'] ?? null;
        if (is_array($documentTypes)) {
            $documentTypes = implode(',', $documentTypes);
        }

        $user->update([
            'kyc_role' => implode(',', $roles),
            'kyc_role_other' => in_array(UserProfile::ROLE_OTHER, $roles, true)
                ? ($validated['kyc_role_other'] ?? null)
                : null,
            'kyc_use_case' => implode(',', $useCases),
            'kyc_use_case_other' => in_array(UserProfile::USE_CASE_OTHER, $useCases, true)
                ? ($validated['kyc_use_case_other'] ?? null)
                : null,
            'kyc_document_types' => $documentTypes,
            'kyc_experience_level' => $validated['kyc_experience_level'] ?? null,
            'kyc_completed_at' => $user->kyc_completed_at ?? now(),
        ]);

        return (new UserResource($user->fresh()))->response();
    }

    /**
     * A single key, a list, or nothing at all, normalized to a list of unique
     * values with its order preserved.
     *
     * @return array<int, mixed>
     */
    protected static function distinct(mixed $value): array
    {
        return array_values(array_unique(Arr::wrap($value), SORT_REGULAR));
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
