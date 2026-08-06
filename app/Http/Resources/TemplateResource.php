<?php

namespace App\Http\Resources;

use App\Models\Template;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Template
 */
class TemplateResource extends JsonResource
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
            'name' => $this->name,
            'category' => $this->category,
            'jurisdiction' => $this->jurisdiction,
            'legal_subtype' => $this->legal_subtype,
            'structure' => $this->structure ?? [],
            'placeholder_fields' => $this->placeholder_fields ?? [],
            'default_for_case_types' => $this->default_for_case_types ?? [],
            'is_system' => $this->isSystem(),
            'created_at' => $this->created_at,
        ];
    }
}
