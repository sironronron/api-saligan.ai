<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Models\VettingRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin VettingRequest
 */
class VettingRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();

        return [
            'id' => $this->id,
            'document_type' => $this->document_type,
            'summary' => $this->summary,
            'jurisdiction' => $this->jurisdiction,
            'service_type' => $this->service_type->value,
            'service_type_label' => $this->service_type->label(),
            'urgency' => $this->urgency->value,
            'urgency_label' => $this->urgency->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'vetting_fee' => $this->vetting_fee,
            'notarization_fee' => $this->notarization_fee,
            'property_value' => $this->property_value,
            'processing_fee' => $this->processing_fee,
            'total_fee' => $this->totalFee(),
            'payment_status' => $this->payment_status->value,
            'gateway_checkout_url' => $this->gateway_checkout_url,
            'deadline_at' => $this->deadline_at,
            'locked_at' => $this->locked_at,
            'session_scheduled_at' => $this->session_scheduled_at,
            'session_link' => $this->session_scheduled_at !== null ? $this->session_link : null,
            'session_provider' => $this->session_provider,
            'certificate_number' => $this->certificate_number,
            'completed_at' => $this->completed_at,
            'cancelled_at' => $this->cancelled_at,
            'cancellation_reason' => $this->cancellation_reason,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'submitter' => $this->whenLoaded('submitter', [
                'id' => $this->submitter?->id,
                'name' => $this->submitter?->name,
            ]),
            'assigned_lawyer' => $this->whenLoaded('assignedLawyer', [
                'id' => $this->assignedLawyer?->id,
                'name' => $this->assignedLawyer?->name,
            ]),
            'document' => $this->whenLoaded('document', [
                'id' => $this->document?->id,
                'title' => $this->document?->title,
                'original_filename' => $this->document?->original_filename,
            ]),
            // How the viewing lawyer is related to this request, if at all.
            'my_match' => $this->when($viewer !== null, function () use ($viewer): ?string {
                if (! $viewer instanceof User) {
                    return null;
                }

                $status = $this->matches()
                    ->where('lawyer_id', $viewer->id)
                    ->value('status');

                return $status !== null ? $status->value : null;
            }),
            'my_match_label' => $this->when($viewer !== null, function () use ($viewer): ?string {
                if (! $viewer instanceof User) {
                    return null;
                }

                $status = $this->matches()
                    ->where('lawyer_id', $viewer->id)
                    ->value('status');

                return $status !== null ? $status->name : null;
            }),
        ];
    }
}
