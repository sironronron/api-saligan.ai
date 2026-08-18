<?php

namespace App\Services\Vetting;

use App\Enums\LawyerVerificationStatus;
use App\Enums\VettingMatchStatus;
use App\Models\LawyerProfile;
use App\Models\User;
use App\Models\VettingRequest;
use Illuminate\Support\Collection;

/**
 * Finds the lawyers a vetting request should be offered to, in the order they
 * should be notified.
 *
 * Matching considers practice area (with an empty list meaning "generalist,
 * takes anything"), region/jurisdiction (a `nationwide` lawyer takes any
 * region), notarial commission for notarization legs, availability, and the
 * lawyer's current workload against their concurrent-assignment cap.
 */
final class LawyerMatcher
{
    /**
     * Map a request's document type to the practice areas that naturally own
     * it. A type with no mapping (e.g. "other") matches every lawyer.
     *
     * @var array<string, array<int, string>>
     */
    private const AREA_BY_DOCUMENT_TYPE = [
        'contract' => ['contracts', 'real_estate', 'corporate'],
        'deed' => ['real_estate', 'land_titles'],
        'lease' => ['real_estate', 'contracts'],
        'power_of_attorney' => ['family_law', 'contracts'],
        'affidavit' => ['litigation'],
        'complaint' => ['litigation'],
        'demand_letter' => ['litigation'],
        'government_letter' => ['other'],
        'corporate' => ['corporate', 'contracts'],
    ];

    public function __construct(
        private readonly VettingSettings $settings,
        private readonly DocumentTypeClassifier $classifier,
    ) {
        //
    }

    /**
     * The eligible lawyers for a request, ordered by ascending workload.
     *
     * @return Collection<int, User>
     */
    public function candidates(VettingRequest $request): Collection
    {
        $alreadyOffered = $request->matches()
            ->whereIn('status', [
                VettingMatchStatus::Notified,
                VettingMatchStatus::Accepted,
                VettingMatchStatus::Declined,
                VettingMatchStatus::Expired,
            ])
            ->pluck('lawyer_id');

        $query = LawyerProfile::query()
            ->with('user')
            ->where('verification_status', LawyerVerificationStatus::Verified)
            ->where('available', true)
            ->whereNotIn('user_id', $alreadyOffered);

        if ($request->includesNotarization()) {
            $query->where('is_notary', true)
                ->whereNotNull('notarial_commission_expires_at')
                ->whereDate('notarial_commission_expires_at', '>=', now()->toDateString());
        }

        $profiles = $query->get()
            ->filter(fn (LawyerProfile $profile): bool => $this->matchesRegion($request, $profile))
            ->filter(fn (LawyerProfile $profile): bool => $this->matchesPracticeArea($request, $profile))
            ->filter(fn (LawyerProfile $profile): bool => $this->hasCapacity($profile));

        return $profiles
            ->sortBy(fn (LawyerProfile $profile): int => $profile->activeRequests()->count())
            ->values()
            ->map(fn (LawyerProfile $profile): User => $profile->user);
    }

    /**
     * Whether the lawyer's region covers the request's jurisdiction. A
     * `nationwide` lawyer covers everywhere; a request without a jurisdiction,
     * or scoped `nationwide`, is matched to any region.
     */
    protected function matchesRegion(VettingRequest $request, LawyerProfile $profile): bool
    {
        if ($profile->region === 'nationwide') {
            return true;
        }

        if ($request->jurisdiction === null || $request->jurisdiction === '' || $request->jurisdiction === 'nationwide') {
            return true;
        }

        return $profile->region === $request->jurisdiction;
    }

    /**
     * Whether the lawyer's practice areas cover the request's document type.
     * A lawyer who listed no areas is a generalist and matches everything; a
     * document type with no area mapping also matches everyone.
     */
    protected function matchesPracticeArea(VettingRequest $request, LawyerProfile $profile): bool
    {
        $areas = $profile->practice_areas ?? [];

        if ($areas === []) {
            return true;
        }

        $slug = $this->classifier->slugFor($request->document_type);
        $required = $slug !== null
            ? (self::AREA_BY_DOCUMENT_TYPE[$slug] ?? [])
            : [];

        if ($required === []) {
            return true;
        }

        return collect($areas)->intersect($required)->isNotEmpty();
    }

    /**
     * Whether the lawyer is under their concurrent-assignment cap.
     */
    protected function hasCapacity(LawyerProfile $profile): bool
    {
        $cap = min(
            $profile->max_concurrent_assignments,
            $this->settings->maxConcurrentAssignments(),
        );

        return $profile->activeRequests()->count() < $cap;
    }
}
