<?php

namespace App\Http\Resources;

use App\Models\Message;
use App\Support\MessageAttachments;
use App\Support\MessageSources;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Message
 */
class MessageResource extends JsonResource
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
            'role' => $this->role->value,
            'content' => $this->content,
            'provider' => $this->provider?->value,
            'sources' => MessageSources::for($this->resource),
            'attachments' => MessageAttachments::for($this->resource),
            'feedback' => $this->feedback,
            'template_id' => $this->metadata['template_id'] ?? null,
            'letter_draft' => $this->metadata['letter_draft'] ?? null,
            // Claims the reply made about actions the turn never took. Sent on
            // reload as well as live, so the caveat stays attached to the
            // answer it qualifies rather than vanishing with the stream.
            'tool_notices' => $this->metadata['tool_notices'] ?? [],
            // The steps this answer went through, for the collapsed "how this
            // was worked out" line the reader can expand under the reply.
            'activity' => $this->metadata['activity'] ?? null,
            'created_at' => $this->created_at,
        ];
    }
}
