<?php

namespace App\Models;

use Database\Factories\TemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'name',
    'category',
    'jurisdiction',
    'legal_subtype',
    'structure',
    'placeholder_fields',
    'default_for_case_types',
    'content',
])]
class Template extends Model
{
    /** @use HasFactory<TemplateFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * The template categories exposed to the template picker.
     */
    public const CATEGORIES = ['formal', 'basic', 'legal', 'custom'];

    /**
     * Legal-correspondence sub-types supported out of the box (PH).
     */
    public const LEGAL_SUBTYPES = [
        'demand_letter',
        'notice_to_explain',
        'notice_of_decision',
        'notice_of_termination',
        'barangay_complaint',
        'reply_to_demand',
        'cease_and_desist',
        'affidavit',
        'custom',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'structure' => 'array',
            'placeholder_fields' => 'array',
            'default_for_case_types' => 'array',
        ];
    }

    /**
     * Whether this is a system-provided (non-user) template.
     */
    public function isSystem(): bool
    {
        return $this->user_id === null;
    }

    /**
     * The user who saved this custom template, when applicable.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
