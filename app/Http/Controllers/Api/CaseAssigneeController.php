<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CaseMemberResource;
use App\Http\Resources\InvitationResource;
use App\Models\LegalCase;
use App\Models\User;
use App\Services\Organizations\OrganizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Who is working a case.
 *
 * Eligibility is the organization, not the address book: only active members
 * of the case's own organization can be put on it. A solo account has no
 * organization, so it has nothing to assign — the case shows its owner and
 * the picker stays away.
 */
class CaseAssigneeController extends Controller
{
    public function __construct(private readonly OrganizationService $organizations) {}

    /**
     * The people on this case: the owner, then everyone assigned.
     */
    public function index(Request $request, LegalCase $case): JsonResponse
    {
        $this->authorize('view', $case);

        return response()->json([
            'owner' => new CaseMemberResource($case->owner),
            'assignees' => CaseMemberResource::collection($case->assignees),
            'can_manage' => $request->user()->can('manageAssignees', $case),
        ]);
    }

    /**
     * Colleagues who could be put on this case: active members of the case's
     * organization who are not already on it. The owner is excluded — they
     * already hold the case and cannot be assigned to it a second time.
     */
    public function candidates(Request $request, LegalCase $case): AnonymousResourceCollection
    {
        $this->authorize('manageAssignees', $case);

        if ($case->organization_id === null) {
            return CaseMemberResource::collection(collect());
        }

        $candidates = User::query()
            ->where('organization_id', $case->organization_id)
            ->where('org_status', User::ORG_STATUS_ACTIVE)
            ->whereKeyNot($case->user_id)
            ->whereDoesntHave('assignedCases', fn ($assigned) => $assigned->whereKey($case->id))
            ->orderBy('name')
            ->get();

        return CaseMemberResource::collection($candidates);
    }

    /**
     * Put someone on the case, either an existing colleague (`user_id`) or an
     * email that is not in the organization yet (`email`).
     *
     * The email path sends an ordinary organization invite carrying this case,
     * so accepting it seats them and lands them on the matter in one step. It
     * needs the same rights and the same free seat any other invite does,
     * which is why it routes through OrganizationService rather than
     * shortcutting it.
     */
    public function store(Request $request, LegalCase $case): JsonResponse
    {
        $this->authorize('manageAssignees', $case);

        $validated = $request->validate([
            'user_id' => ['required_without:email', 'missing_with:email', 'integer', 'exists:users,id'],
            'email' => ['required_without:user_id', 'missing_with:user_id', 'email', 'max:255'],
        ]);

        abort_if(
            $case->organization_id === null,
            422,
            'This case is not part of an organization. Create one to share cases with colleagues.',
        );

        if (isset($validated['email'])) {
            return $this->inviteAndAssign($request, $case, $validated['email']);
        }

        $member = User::findOrFail($validated['user_id']);

        abort_unless(
            $member->organization_id === $case->organization_id && $member->org_status === User::ORG_STATUS_ACTIVE,
            422,
            'Only active members of your organization can be assigned to a case.',
        );

        abort_if($member->id === $case->user_id, 422, 'The case owner is already on this case.');

        // syncWithoutDetaching rather than attach: assigning someone who is
        // already on the case is a no-op the caller should not have to guard.
        $case->assignees()->syncWithoutDetaching([
            $member->id => ['assigned_by' => $request->user()->id],
        ]);

        return response()->json([
            'assignees' => CaseMemberResource::collection($case->load('assignees')->assignees),
        ], 201);
    }

    /**
     * Take someone off the case. Removing yourself is allowed for an assignee
     * who wants off a matter, which is why this does not require the full
     * manage right when the target is the caller.
     */
    public function destroy(Request $request, LegalCase $case, User $user): JsonResponse
    {
        $removingSelf = $user->id === $request->user()->id;

        if (! $removingSelf) {
            $this->authorize('manageAssignees', $case);
        } else {
            $this->authorize('view', $case);

            // Leaving is the one roster change that skips manageAssignees, so
            // the closed/archived freeze has to be repeated here by hand.
            abort_if($case->isReadOnly(), 422, 'This case is closed or archived. Reopen it to change who is on it.');
        }

        abort_if($user->id === $case->user_id, 422, 'The case owner cannot be removed from their own case.');

        $case->assignees()->detach($user->id);

        return response()->json([
            'assignees' => CaseMemberResource::collection($case->load('assignees')->assignees),
        ]);
    }

    /**
     * Invite an email into the organization with this case attached, so
     * acceptance both seats them and assigns them.
     */
    private function inviteAndAssign(Request $request, LegalCase $case, string $email): JsonResponse
    {
        $email = strtolower(trim($email));

        // Already a colleague? Then this is an assignment, not an invite — the
        // person typing an address should not have to know which it is.
        $existing = User::query()
            ->where('email', $email)
            ->where('organization_id', $case->organization_id)
            ->first();

        if ($existing !== null) {
            abort_if($existing->id === $case->user_id, 422, 'The case owner is already on this case.');

            abort_unless(
                $existing->org_status === User::ORG_STATUS_ACTIVE,
                422,
                'That colleague’s membership is suspended. Reactivate them before assigning the case.',
            );

            $case->assignees()->syncWithoutDetaching([
                $existing->id => ['assigned_by' => $request->user()->id],
            ]);

            return response()->json([
                'assignees' => CaseMemberResource::collection($case->load('assignees')->assignees),
            ], 201);
        }

        // Seat checks, duplicate-invite checks, and the "only admins may
        // invite" rule all live in the service — an invite sent from a case is
        // an ordinary invite and must not get a softer path.
        $invitation = $this->organizations->invite($request->user(), $case->organization, $email);

        $invitation->update(['case_id' => $case->id]);

        return response()->json([
            'invitation' => new InvitationResource($invitation->fresh()),
            'assignees' => CaseMemberResource::collection($case->load('assignees')->assignees),
        ], 201);
    }
}
