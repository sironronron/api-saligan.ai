<?php

namespace App\Http\Resources;

use App\Models\Label;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Label
 */
class LabelResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'group' => $this->group,
            'color' => $this->color,
            'position' => $this->position,
            'scope' => $this->scope(),
            'is_editable' => $user !== null && $this->manageableBy($user),
            'usage_count' => $this->when(isset($this->usage_count), fn () => (int) $this->usage_count),
            // Present only when the label arrives through a record's own
            // labels relation, where the pivot records how it got there.
            'source' => $this->whenPivotLoaded('labelables', fn () => $this->pivot->source),
            'confidence' => $this->whenPivotLoaded('labelables', fn () => $this->pivot->confidence),
        ];
    }

    /**
     * Where this label comes from, for the picker to badge it.
     */
    protected function scope(): string
    {
        return match (true) {
            $this->isSystem() => 'system',
            $this->isOrganizationOwned() => 'organization',
            default => 'personal',
        };
    }
}
