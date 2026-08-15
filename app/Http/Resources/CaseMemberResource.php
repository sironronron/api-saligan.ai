<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A person shown against a case — the owner, an assignee, or a candidate in
 * the assign picker.
 *
 * Deliberately not UserResource: that carries the KYC answers, terms state,
 * and billing-adjacent flags of whoever it describes, and a case only needs
 * enough to name a colleague and draw their avatar. Assigning someone to a
 * matter should not hand every other person on that matter their profile.
 *
 * @mixin User
 */
class CaseMemberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'org_role' => $this->org_role,
            'org_status' => $this->org_status,
        ];
    }
}
