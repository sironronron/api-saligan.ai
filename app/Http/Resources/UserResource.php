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
        ];
    }
}
