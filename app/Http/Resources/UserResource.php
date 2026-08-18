<?php

namespace App\Http\Resources;

use App\Models\LegalDocument;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'is_admin' => $this->is_admin,
            'organization_id' => $this->organization_id,
            'org_role' => $this->org_role,
            'org_status' => $this->org_status,
            // Named here so the suspension notice can say which workspace
            // locked the member out: every organization endpoint is closed to
            // them by then, and "an organization has suspended you" is not an
            // answer someone on a shared plan can act on.
            'organization_name' => $this->organization?->name,
            'kyc_role' => $this->kyc_role,
            'kyc_role_other' => $this->kyc_role_other,
            'kyc_use_case' => $this->kyc_use_case,
            'kyc_use_case_other' => $this->kyc_use_case_other,
            'kyc_document_types' => $this->kyc_document_types,
            'kyc_experience_level' => $this->kyc_experience_level,
            'kyc_completed_at' => $this->kyc_completed_at,
            'tour_completed_at' => $this->tour_completed_at,
            'terms_accepted_at' => $this->terms_accepted_at,
            'terms_version' => $this->terms_version,
            'terms_accepted' => $this->hasAcceptedTerms(),
            'terms_current_version' => LegalDocument::currentVersion(),
            'marketing_opt_in' => $this->marketing_opt_in,
            'created_at' => $this->created_at,
            // A lightweight summary so the client can route lawyers (register,
            // pending, workspace) without an extra round trip. The full profile
            // and the selectable options come from /lawyer/profile.
            'lawyer_profile' => $this->whenLoaded('lawyerProfile', function (): ?array {
                if ($this->lawyerProfile === null) {
                    return null;
                }

                return [
                    'id' => $this->lawyerProfile->id,
                    'full_name' => $this->lawyerProfile->full_name,
                    'verification_status' => $this->lawyerProfile->verification_status->value,
                    'is_notary' => $this->lawyerProfile->is_notary,
                    'available' => $this->lawyerProfile->available,
                    'practice_areas' => $this->lawyerProfile->practice_areas ?? [],
                    'region' => $this->lawyerProfile->region,
                ];
            }),
        ];
    }
}
