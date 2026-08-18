<?php

namespace App\Http\Resources;

use App\Models\NotarialJournalEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin NotarialJournalEntry
 */
class NotarialJournalEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'lawyer_id' => $this->lawyer_id,
            'vetting_request_id' => $this->vetting_request_id,
            'signer_name' => $this->signer_name,
            'id_type' => $this->id_type,
            'id_number' => $this->id_number,
            'document_type' => $this->document_type,
            'verification_method' => $this->verification_method,
            'certificate_number' => $this->certificate_number,
            'session_recording_ref' => $this->session_recording_ref,
            'notarized_at' => $this->notarized_at,
            'created_at' => $this->created_at,
        ];
    }
}
