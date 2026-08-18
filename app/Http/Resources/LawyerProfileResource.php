<?php

namespace App\Http\Resources;

use App\Models\LawyerProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LawyerProfile
 */
class LawyerProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'full_name' => $this->full_name,
            'bar_number' => $this->bar_number,
            'bar_jurisdiction' => $this->bar_jurisdiction,
            'ptr_number' => $this->ptr_number,
            'practice_areas' => $this->practice_areas ?? [],
            'region' => $this->region,
            'city' => $this->city,
            'phone' => $this->phone,
            'is_notary' => $this->is_notary,
            'can_notarize' => $this->canNotarize(),
            'notarial_commission_number' => $this->notarial_commission_number,
            'notarial_commission_issuer' => $this->notarial_commission_issuer,
            'notarial_commission_expires_at' => $this->notarial_commission_expires_at?->toDateString(),
            'verification_status' => $this->verification_status->value,
            'verification_reason' => $this->verification_reason,
            'verification_reviewed_at' => $this->verification_reviewed_at,
            'verified_at' => $this->verified_at,
            'available' => $this->available,
            'max_concurrent_assignments' => $this->max_concurrent_assignments,
            'notify_email' => $this->notify_email,
            'notify_sms' => $this->notify_sms,
            'notify_push' => $this->notify_push,
            'notify_in_app' => $this->notify_in_app,
            'profile_changed_at' => $this->profile_changed_at,
            'has_id_document' => $this->id_document_path !== null,
            'has_bar_membership_document' => $this->bar_membership_document_path !== null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            // Loaded by the admin verification queue so a reviewer can reach
            // the applicant's account details from the same row.
            'user' => $this->whenLoaded('user', [
                'id' => $this->user?->id,
                'name' => $this->user?->name,
                'email' => $this->user?->email,
                'created_at' => $this->user?->created_at,
            ]),
        ];
    }
}
