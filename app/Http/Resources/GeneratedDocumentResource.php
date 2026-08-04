<?php

namespace App\Http\Resources;

use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

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
            'title' => $this->derivedTitle(),
            'created_at' => $this->created_at,
        ];
    }

    /**
     * Derive a short title from the first non-empty line of the draft.
     */
    protected function derivedTitle(): string
    {
        foreach (preg_split('/\R/', (string) $this->content) ?: [] as $line) {
            $line = trim($line, " \t#*-");

            if ($line !== '') {
                return Str::limit($line, 80);
            }
        }

        return $this->conversation?->title ?? 'Generated document';
    }
}
