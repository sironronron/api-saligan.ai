<?php

namespace App\Http\Resources;

use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Message
 */
class GeneratedDocumentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $letterDraft = $this->metadata['letter_draft'] ?? null;

        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'conversation_title' => $this->conversation?->title,
            'case_id' => $this->conversation?->case_id,
            'case_title' => $this->conversation?->case?->title,
            'title' => is_string($letterDraft['title'] ?? null) ? $letterDraft['title'] : $this->draftTitle(),
            'content' => $this->content,
            'letter_draft' => $letterDraft,
            'created_at' => $this->created_at,
        ];
    }
}
