<?php

namespace App\Http\Resources;

use App\Models\LegalCase;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LegalCase
 */
class LegalCaseResource extends JsonResource
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
            'case_type' => $this->case_type,
            'reference' => $this->reference,
            'priority' => $this->priority,
            'status' => $this->status,
            'closed_at' => $this->closed_at,
            'description' => $this->description,
            'related_parties' => $this->related_parties ?? [],
            'due_date' => $this->due_date?->toDateString(),
            'tags' => $this->tags ?? [],
            'default_template_id' => $this->default_template_id,
            'default_template' => $this->whenLoaded('defaultTemplate', fn () => new TemplateResource($this->defaultTemplate)),
            'conversation_id' => $this->whenLoaded('conversation', fn () => $this->conversation?->id),
            'active_conversation_id' => $this->whenLoaded('conversation', fn () => $this->conversation?->id),
            'conversations' => ConversationResource::collection($this->whenLoaded('conversations')),
            'messages_count' => $this->messages_count,
            'open_tasks_count' => $this->open_tasks_count,
            'total_tasks_count' => $this->total_tasks_count,
            'last_message_at' => $this->last_message_at,
            'last_message_snippet' => $this->last_message_snippet,
            'archived_at' => $this->archived_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'messages' => MessageResource::collection($this->whenLoaded('messages')),
            'tasks' => $this->whenLoaded('tasks'),
        ];
    }
}
