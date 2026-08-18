<?php

namespace App\Http\Resources;

use App\Models\VettingMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin VettingMessage
 */
class VettingMessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'vetting_request_id' => $this->vetting_request_id,
            'author' => [
                'id' => $this->author?->id,
                'name' => $this->author?->name,
            ],
            'body' => $this->body,
            'created_at' => $this->created_at,
        ];
    }
}
