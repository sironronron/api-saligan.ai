<?php

namespace App\Http\Resources;

use App\Models\Message;
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
            'created_at' => $this->created_at,
        ];
    }
}
