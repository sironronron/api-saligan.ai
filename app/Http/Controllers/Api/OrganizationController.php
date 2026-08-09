<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InvitationResource;
use App\Http\Resources\UserResource;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use App\Services\Organizations\OrganizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OrganizationController extends Controller
{
    public function __construct(private readonly OrganizationService $organizations) {}

    /**
     * Show the current user's organization, its seat usage, and its members.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->hasActiveMembership(), 404, 'You do not belong to an organization yet.');

        $organization = $user->organization;

        return response()->json([
            'data' => $this->organizationPayload($organization, $user),
        ]);
    }

    /**
     * Create a new organization and make the authenticated user its owner.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $user = $request->user();

        abort_if($user->hasOrganization(), 422, 'You already belong to an organization.');

        $this->organizations->createOrganization($validated['name'], $user);

        return (new UserResource($user->fresh()))->response()->setStatusCode(201);
    }

    /**
     * List the members of the user's organization.
     */
    public function members(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        abort_unless($user->hasActiveMembership(), 403);

        $members = $user->organization->users()
            ->orderBy('org_status')
            ->orderBy('name')
            ->get();

        return UserResource::collection($members);
    }

    /**
     * Remove a member from the organization, freeing their seat immediately.
     */
    public function removeMember(Request $request, User $member): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->hasActiveMembership(), 403);

        $this->organizations->removeMember($user, $user->organization, $member);

        return response()->json(null, 204);
    }

    /**
     * Suspend a member's access to the organization.
     */
    public function suspendMember(Request $request, User $member): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->hasActiveMembership(), 403);

        $this->organizations->suspendMember($user, $user->organization, $member);

        return response()->json(['data' => (new UserResource($member->fresh()))->resolve()]);
    }

    /**
     * Restore a suspended member's access.
     */
    public function resumeMember(Request $request, User $member): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->hasActiveMembership(), 403);

        $this->organizations->resumeMember($user, $user->organization, $member);

        return response()->json(['data' => (new UserResource($member->fresh()))->resolve()]);
    }

    /**
     * List the invitations sent by the organization.
     */
    public function indexInvitations(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        abort_unless($user->canManageOrganization(), 403, 'Only organization admins can view invitations.');

        $invitations = $user->organization->invitations()
            ->with('invitedBy')
            ->latest()
            ->get();

        return InvitationResource::collection($invitations);
    }

    /**
     * Invite an email to the organization. Requires an admin, a free seat,
     * and an email not already tied to another organization.
     */
    public function storeInvitation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        $user = $request->user();

        abort_unless($user->hasActiveMembership(), 403);

        $invitation = $this->organizations->invite($user, $user->organization, $validated['email']);

        return (new InvitationResource($invitation->load('invitedBy')))->response()->setStatusCode(201);
    }

    /**
     * Revoke a pending invitation, freeing the seat it reserved.
     */
    public function revokeInvitation(Request $request, Invitation $invitation): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->canManageOrganization(), 403, 'Only organization admins can revoke invitations.');

        abort_unless($invitation->organization_id === $user->organization_id, 403, 'This invitation does not belong to your organization.');

        $this->organizations->revoke($invitation);

        return response()->json(null, 204);
    }

    /**
     * Accept an invitation on behalf of the authenticated user. The user's
     * email must match the invite and a seat must be available.
     */
    public function acceptInvitation(Request $request, Invitation $invitation): JsonResponse
    {
        $this->organizations->acceptInvite($request->user(), $invitation);

        return (new UserResource($request->user()->fresh('organization')))->response();
    }

    /**
     * The organization payload shared by the show endpoint.
     *
     * @return array<string, mixed>
     */
    protected function organizationPayload(Organization $organization, User $user): array
    {
        $subscription = $organization->subscription;

        return [
            'id' => $organization->id,
            'name' => $organization->name,
            'role' => $user->org_role,
            'created_at' => $organization->created_at,
            'seats' => [
                'purchased' => $subscription?->seats_purchased,
                'used' => $organization->seatsUsed(),
                'pending_invites' => $organization->pendingInvitesCount(),
                'free' => $organization->freeSeats(),
                'price_per_seat' => $subscription?->price_per_seat,
            ],
            'members' => $organization->users()
                ->orderBy('org_status')
                ->orderBy('name')
                ->get()
                ->map(fn (User $member): array => [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $member->email,
                    'org_role' => $member->org_role,
                    'org_status' => $member->org_status,
                ])
                ->all(),
        ];
    }
}
