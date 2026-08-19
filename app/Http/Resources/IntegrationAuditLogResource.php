<?php

namespace App\Http\Resources;

use App\Models\IntegrationAuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin IntegrationAuditLog
 */
class IntegrationAuditLogResource extends JsonResource
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
            'action' => $this->action,
            'provider' => $this->provider?->value,
            'provider_label' => $this->provider?->label(),
            'details' => $this->details ?? [],
            'ip_address' => $this->ip_address,
            'created_at' => $this->created_at?->toIso8601String(),
            // The actor is resolved for display; the row keeps only the id so
            // the trail survives account deletion.
            'actor' => $this->when(
                $this->user_id !== null,
                fn (): ?array => $this->actor?->only(['id', 'name', 'email']),
            ),
        ];
    }
}
