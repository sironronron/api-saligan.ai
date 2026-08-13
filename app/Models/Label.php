<?php

namespace App\Models;

use App\Enums\LabelKind;
use Database\Factories\LabelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

#[Fillable([
    'kind',
    'organization_id',
    'user_id',
    'slug',
    'name',
    'description',
    'group',
    'color',
    'position',
])]
class Label extends Model
{
    /** @use HasFactory<LabelFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * How many custom labels of one kind an owner may create. A shared
     * vocabulary that grows without bound is no longer a vocabulary.
     */
    public const MAX_CUSTOM_PER_OWNER = 100;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => LabelKind::class,
            'archived_at' => 'datetime',
        ];
    }

    /**
     * The organization that owns this label, when it is a shared custom term.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * The user who created this label. Set for personal labels, and kept on
     * organization labels purely for attribution.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The documents filed under this label.
     */
    public function documents(): MorphToMany
    {
        return $this->morphedByMany(Document::class, 'labelable')
            ->withPivot(['source', 'confidence', 'assigned_by'])
            ->withTimestamps();
    }

    /**
     * The conversation threads carrying this label.
     */
    public function conversations(): MorphToMany
    {
        return $this->morphedByMany(Conversation::class, 'labelable')
            ->withPivot(['source', 'confidence', 'assigned_by'])
            ->withTimestamps();
    }

    /**
     * Whether this is a system-provided term, seeded for every account.
     */
    public function isSystem(): bool
    {
        return $this->organization_id === null && $this->user_id === null;
    }

    /**
     * Whether this label is owned by an organization and therefore shared
     * with every active member of it.
     */
    public function isOrganizationOwned(): bool
    {
        return $this->organization_id !== null;
    }

    /**
     * Scope to the labels the given user may see: the seeded system terms,
     * their own personal terms, and — while their membership is active — the
     * terms shared by their organization.
     *
     * Unlike templates, which reach organization-mates through their owner,
     * ownership lives in a column here. Labels are fetched on every documents
     * and threads page load, so this stays a flat filter with no join.
     *
     * @param  Builder<Label>  $query
     * @return Builder<Label>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $query) use ($user): void {
            $query
                ->where(fn (Builder $q) => $q->whereNull('organization_id')->whereNull('user_id'))
                ->orWhere(fn (Builder $q) => $q->where('user_id', $user->id)->whereNull('organization_id'));

            if ($user->hasActiveMembership()) {
                $query->orWhere('organization_id', $user->organization_id);
            }
        });
    }

    /**
     * Scope that attaches how many records currently carry each label, so the
     * UI can warn before deleting a term other people's case files rely on.
     *
     * @param  Builder<Label>  $query
     * @return Builder<Label>
     */
    public function scopeWithUsageCount(Builder $query): Builder
    {
        return $query->addSelect(['usage_count' => DB::table('labelables')
            ->selectRaw('count(*)')
            ->whereColumn('label_id', 'labels.id')]);
    }

    /**
     * Scope to labels that have not been archived.
     *
     * @param  Builder<Label>  $query
     * @return Builder<Label>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /**
     * Resolve the labels a user is trying to apply, refusing anything they
     * cannot see, anything belonging to the other axis (a thread tag filed on
     * a document), and any set larger than the kind allows.
     *
     * @param  array<int, string>  $labelIds
     * @return Collection<int, Label>
     *
     * @throws ValidationException
     */
    public static function resolveForAssignment(User $user, array $labelIds, LabelKind $kind): Collection
    {
        $labelIds = array_values(array_unique($labelIds));

        if (count($labelIds) > $kind->maxPerRecord()) {
            throw ValidationException::withMessages([
                'label_ids' => 'You may apply at most '.$kind->maxPerRecord().' '.Str::lower($kind->label()).'s.',
            ]);
        }

        $labels = static::query()
            ->visibleTo($user)
            ->active()
            ->where('kind', $kind)
            ->whereIn('id', $labelIds)
            ->get();

        if ($labels->count() !== count($labelIds)) {
            throw ValidationException::withMessages([
                'label_ids' => 'One or more of the selected labels is unavailable.',
            ]);
        }

        return $labels;
    }

    /**
     * Whether the given user may see and apply this label.
     */
    public function visibleTo(User $user): bool
    {
        if ($this->isSystem()) {
            return true;
        }

        if ($this->isOrganizationOwned()) {
            return $user->hasActiveMembership() && $this->organization_id === $user->organization_id;
        }

        return $this->user_id === $user->id;
    }

    /**
     * Whether the given user may rename, recolor, or delete this label.
     *
     * System terms are immutable for everyone. An organization's terms are a
     * shared vocabulary — renaming one rewrites what every colleague sees and
     * deleting one strips it from their case files — so editing them is left
     * to the owner and admins.
     */
    public function manageableBy(User $user): bool
    {
        if ($this->isSystem()) {
            return false;
        }

        if ($this->isOrganizationOwned()) {
            return $user->hasActiveMembership()
                && $this->organization_id === $user->organization_id
                && $user->canManageOrganization();
        }

        return $this->user_id === $user->id;
    }
}
