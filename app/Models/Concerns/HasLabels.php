<?php

namespace App\Models\Concerns;

use App\Models\Label;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Collection;

/**
 * Attaches the shared label vocabulary — case-file categories on documents,
 * tags on threads — to a model through the polymorphic labelables pivot.
 */
trait HasLabels
{
    /**
     * The labels applied to this record.
     */
    public function labels(): MorphToMany
    {
        return $this->morphToMany(Label::class, 'labelable')
            ->withPivot(['source', 'confidence', 'assigned_by'])
            ->withTimestamps()
            ->orderBy('position')
            ->orderBy('name');
    }

    /**
     * Replace this record's labels with the given set, recording who applied
     * them and whether a person or the classifier chose them.
     *
     * @param  Collection<int, Label>|array<int, Label>  $labels
     */
    public function syncLabels(Collection|array $labels, ?User $actor = null, string $source = 'user'): void
    {
        $payload = Collection::make($labels)
            ->mapWithKeys(fn (Label $label) => [$label->id => [
                'source' => $source,
                'assigned_by' => $actor?->id,
            ]])
            ->all();

        $this->labels()->sync($payload);
    }

    /**
     * Replace this record's labels with what the classifier suggested, keeping
     * each suggestion's own confidence so the UI can tell a near-certain
     * filing from a borderline one.
     *
     * @param  array<int, array{label: Label, confidence: float}>  $suggestions
     */
    public function syncSuggestedLabels(array $suggestions): void
    {
        $payload = [];

        foreach ($suggestions as $suggestion) {
            $payload[$suggestion['label']->id] = [
                'source' => 'ai',
                'confidence' => $suggestion['confidence'],
                'assigned_by' => null,
            ];
        }

        $this->labels()->sync($payload);
    }

    /**
     * Scope to records carrying at least one of the given labels.
     *
     * @param  Builder<static>  $query
     * @param  array<int, string>  $labelIds
     * @return Builder<static>
     */
    public function scopeWithAnyLabels(Builder $query, array $labelIds): Builder
    {
        if ($labelIds === []) {
            return $query;
        }

        return $query->whereHas('labels', fn (Builder $q) => $q->whereIn('labels.id', $labelIds));
    }

    /**
     * Scope to records carrying every one of the given labels. This is the
     * filter that narrows a case file to the documents doing two jobs at once
     * — the bank records that are both documentary evidence and financial.
     *
     * @param  Builder<static>  $query
     * @param  array<int, string>  $labelIds
     * @return Builder<static>
     */
    public function scopeWithAllLabels(Builder $query, array $labelIds): Builder
    {
        foreach ($labelIds as $labelId) {
            $query->whereHas('labels', fn (Builder $q) => $q->where('labels.id', $labelId));
        }

        return $query;
    }
}
