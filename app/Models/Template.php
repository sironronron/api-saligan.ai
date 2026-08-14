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
     * Whether this template supports verbatim mode: the original uploaded
     * file is retained AND placeholder fields have been extracted from it.
     * In verbatim mode, the AI supplies fill values instead of drafting a
     * new document, preserving the original letterhead, logo, and formatting.
     */
    public function isVerbatimTemplate(): bool
    {
        return $this->isDocxFileTemplate() && count($this->placeholder_fields ?? []) > 0;
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
     * Scope to a stable "closest to this user first" ordering: the user's own
     * templates, then their organization's, then the system library.
     *
     * Several templates routinely share a legal sub-type — a firm that saved
     * its own demand letter has one alongside the system library's. Picking
     * between them with `first()` and no ORDER BY leaves the choice to whatever
     * order the database happens to return rows in, so the same user asking for
     * the same sub-type can be drafted from a different template run to run,
     * including a colleague's instead of their own. Ordering makes the answer
     * both deterministic and the one they would expect.
     *
     * @param  Builder<Template>  $query
     */
    public function scopeClosestTo(Builder $query, User $user): Builder
    {
        return $query
            ->orderByRaw(
                'case when templates.user_id = ? then 0 when templates.user_id is null then 2 else 1 end',
                [$user->id],
            )
            ->orderByDesc('templates.created_at')
            // Final tie-break: templates created in the same second would
            // otherwise still be ordered arbitrarily.
            ->orderBy('templates.id');
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
