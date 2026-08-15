<?php

namespace App\Http\Resources;

use App\Models\Invitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Invitation
 */
class InvitationResource extends JsonResource
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
            'email' => $this->email,
            'status' => $this->status,
            'token' => $this->when(
                $request->user()?->canManageOrganization(),
                $this->token,
            ),
            'expires_at' => $this->expires_at?->toIso8601String(),
            // Loaded only where the reader is the recipient rather than the
            // sender: an admin listing their own invites already knows the
            // organization, the invited user is being told which one.
            'organization' => $this->whenLoaded('organization', fn (): ?array => $this->organization?->only(['id', 'name'])),
            'invited_by' => $this->whenLoaded('invitedBy', fn (): ?array => $this->invitedBy?->only(['id', 'name', 'email'])),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
