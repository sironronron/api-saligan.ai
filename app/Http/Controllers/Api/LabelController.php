<?php

namespace App\Http\Controllers\Api;

use App\Enums\LabelKind;
use App\Http\Controllers\Controller;
use App\Http\Resources\LabelResource;
use App\Models\Label;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LabelController extends Controller
{
    /**
     * List the labels the user may apply: the seeded system vocabulary, the
     * custom terms shared by their organization, and their own personal ones.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'kind' => ['nullable', Rule::enum(LabelKind::class)],
        ]);

        $labels = Label::query()
            ->visibleTo($request->user())
            ->active()
            ->withUsageCount()
            ->when(
                isset($validated['kind']),
                fn ($query) => $query->where('kind', $validated['kind']),
            )
            ->orderBy('kind')
            ->orderBy('position')
            ->orderBy('name')
            ->get();

        return LabelResource::collection($labels);
    }

    /**
     * Create a custom label. A member of an organization always creates it for
     * the whole organization — a shared vocabulary is the point, and a private
     * term made by accident would quietly fragment it. Users who belong to no
     * organization get a personal term instead.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'kind' => ['required', Rule::enum(LabelKind::class)],
            'name' => ['required', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:20'],
        ]);

        $kind = LabelKind::from($validated['kind']);
        $slug = Str::slug($validated['name']);

        if ($slug === '') {
            throw ValidationException::withMessages([
                'name' => 'The name must contain at least one letter or number.',
            ]);
        }

        $this->ensureSlugIsAvailable($request, $kind, $slug);
        $this->ensureCustomLabelQuotaRemains($request, $kind);

        // Appended after the caller's existing custom terms, so a fresh label
        // never collides with the positions a reorder has already assigned.
        $maxPosition = Label::query()
            ->where('kind', $kind)
            ->when(
                $user->hasActiveMembership(),
                fn ($query) => $query->where('organization_id', $user->organization_id),
                fn ($query) => $query->whereNull('organization_id')->where('user_id', $user->id),
            )
            ->max('position');

        $label = Label::create([
            'kind' => $kind,
            'organization_id' => $user->hasActiveMembership() ? $user->organization_id : null,
            'user_id' => $user->id,
            'slug' => Str::limit($slug, 60, ''),
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'color' => $validated['color'] ?? null,
            'position' => (int) ($maxPosition ?? 999) + 1,
        ]);

        return (new LabelResource($label))->response()->setStatusCode(201);
    }

    /**
     * Rename or restyle a custom label.
     *
     * The slug stays fixed: it is the label's identity, and rewriting it on
     * every rename would break saved filters for everyone else in the
     * organization while gaining nothing.
     */
    public function update(Request $request, Label $label): LabelResource
    {
        abort_unless($label->manageableBy($request->user()), 403);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:20'],
        ]);

        $label->update($validated);

        return new LabelResource($label->fresh());
    }

    /**
     * Delete a custom label. The assignments go with it, so the documents and
     * threads that carried it simply lose the term; nothing else is touched.
     */
    public function destroy(Request $request, Label $label): JsonResponse
    {
        abort_unless($label->manageableBy($request->user()), 403);

        $label->delete();

        return response()->json(null, 204);
    }

    /**
     * Persist a new manual ordering for the caller's custom document
     * categories.
     *
     * Only labels the caller may manage move — their own personal terms and,
     * for an owner or admin, their organization's shared terms. The seeded
     * system vocabulary keeps its fixed order and any label the client
     * omitted is appended behind the given ones, so a stale list never leaves
     * the vocabulary half-ordered.
     */
    public function reorder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ordered_ids' => ['required', 'array'],
            'ordered_ids.*' => ['required', 'uuid', 'distinct'],
        ]);

        $labels = Label::query()
            ->where('kind', LabelKind::DocumentCategory)
            ->get()
            ->filter(fn (Label $label) => $label->manageableBy($request->user()))
            ->keyBy(fn (Label $label) => $label->id);

        $position = 1000;

        foreach ($validated['ordered_ids'] as $labelId) {
            $label = $labels->get($labelId);

            if ($label === null) {
                continue;
            }

            $label->update(['position' => $position++]);
            $labels->forget($labelId);
        }

        foreach ($labels as $label) {
            $label->update(['position' => $position++]);
        }

        return response()->json(['message' => 'Order updated']);
    }

    /**
     * Reject a slug that already exists anywhere the user can see it. The
     * partial unique indexes only guard within an ownership scope, so without
     * this an organization could shadow a system term and the picker would
     * offer "Urgent" twice with no way to tell them apart.
     */
    protected function ensureSlugIsAvailable(Request $request, LabelKind $kind, string $slug): void
    {
        $exists = Label::query()
            ->visibleTo($request->user())
            ->where('kind', $kind)
            ->where('slug', $slug)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'name' => 'A label with that name already exists.',
            ]);
        }
    }

    /**
     * Hold the custom vocabulary to a workable size.
     */
    protected function ensureCustomLabelQuotaRemains(Request $request, LabelKind $kind): void
    {
        $user = $request->user();

        $used = Label::query()
            ->where('kind', $kind)
            ->when(
                $user->hasActiveMembership(),
                fn ($query) => $query->where('organization_id', $user->organization_id),
                fn ($query) => $query->whereNull('organization_id')->where('user_id', $user->id),
            )
            ->count();

        if ($used >= Label::MAX_CUSTOM_PER_OWNER) {
            throw ValidationException::withMessages([
                'name' => 'You have reached the maximum of '.Label::MAX_CUSTOM_PER_OWNER.' custom labels.',
            ]);
        }
    }
}
