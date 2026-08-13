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
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'conversation_title' => $this->conversation?->title,
            'case_id' => $this->conversation?->case_id,
            'case_title' => $this->conversation?->case?->title,
            'title' => $this->draftTitle(),
            'content' => $this->content,
            'created_at' => $this->created_at,
        ];
    }
}
