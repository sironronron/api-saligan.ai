<?php

namespace App\Models;

use Database\Factories\TemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
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
    'original_path',
    'mime_type',
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
     * Whether this template was uploaded as a .docx file kept verbatim as the
     * single source of truth for rendering. Its original file is edited in
     * place when filling placeholders; the stored text is for AI analysis
     * only and is never used to regenerate the document.
     */
    public function isDocxFileTemplate(): bool
    {
        return $this->original_path !== null;
    }

    /**
     * Scope to templates the given user may access: system templates, the
     * user's own templates, and templates owned by other active members of
     * the user's organization.
     *
     * @param  Builder<Template>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $query) use ($user): void {
            $query->whereNull('user_id')
                ->orWhere('user_id', $user->id);

            if ($user->organization_id !== null) {
                $query->orWhereHas('user', fn (Builder $q) => $q
                    ->where('organization_id', $user->organization_id)
                    ->where('org_status', User::ORG_STATUS_ACTIVE));
            }
        });
    }

    /**
     * Whether the given user may access this template.
     */
    public function visibleTo(User $user): bool
    {
        if ($this->isSystem()) {
            return true;
        }

        if ($this->user_id === $user->id) {
            return true;
        }

        if ($user->organization_id === null) {
            return false;
        }

        return $this->user !== null
            && $this->user->organization_id === $user->organization_id
            && $this->user->org_status === User::ORG_STATUS_ACTIVE;
    }

    /**
     * The user who saved this custom template, when applicable.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
