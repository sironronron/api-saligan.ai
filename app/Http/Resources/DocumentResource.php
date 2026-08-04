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
            'title' => $this->title,
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'status' => $this->status->value,
            'error_message' => $this->error_message,
            'chunk_count' => $this->whenCounted('chunks', fn (int $count) => $count),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
