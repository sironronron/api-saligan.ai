<?php

namespace App\Http\Resources;

use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Document
 */
class DocumentResource extends JsonResource
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
            'user_id' => $this->user_id,
            // The library now carries case documents a colleague uploaded, so
            // the shelf has to say whose file it is. Absent when the relation
            // was not loaded — the caller's own uploads need no attribution.
            'uploaded_by' => $this->whenLoaded('user', fn () => $this->user->name),
            'case_id' => $this->case_id,
            'title' => $this->title,
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'status' => $this->status->value,
            'error_message' => $this->error_message,
            'chunk_count' => $this->whenCounted('chunks', fn (int $count) => $count),
            'categories' => LabelResource::collection($this->whenLoaded('labels')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
