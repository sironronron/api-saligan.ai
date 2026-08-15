<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InvitationResource;
use App\Http\Resources\UserResource;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use App\Services\Organizations\OrganizationService;
use App\Support\PlanFeatures;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;

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

        // Gated at creation rather than at invitation: a one-person
        // organization is the shape every team starts as, so a plan that
        // cannot hold a team should never get as far as owning one.
        PlanFeatures::ensureHas($user, PlanFeatures::TEAMS);

        $this->organizations->createOrganization($validated['name'], $user);

        return (new UserResource($user->fresh()))->response()->setStatusCode(201);
    }

    /**
     * Edit the organization's profile — its name, what it does, and its site.
     *
     * Every field is `sometimes`: the settings form saves one section at a
     * time, and a request that omits a field means "leave it alone" rather
     * than "clear it". Clearing is done by sending an empty string.
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->hasActiveMembership(), 404, 'You do not belong to an organization yet.');

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'website' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $organization = $this->organizations->updateProfile($user, $user->organization, $validated);

        return response()->json([
            'data' => $this->organizationPayload($organization, $user),
        ]);
    }

    /**
     * Replace the organization's logo.
     */
    public function storeLogo(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->hasActiveMembership(), 404, 'You do not belong to an organization yet.');

        $request->validate([
            // Raster only. SVG is markup, and markup served back from our own
            // origin is a scripting hole no logo is worth.
            'logo' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:2048'],
        ]);

        $organization = $this->organizations->setLogo($user, $user->organization, $request->file('logo'));

        return response()->json([
            'data' => $this->organizationPayload($organization, $user),
        ]);
    }

    /**
     * Drop the logo, falling back to the drawn initial.
     */
    public function destroyLogo(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->hasActiveMembership(), 404, 'You do not belong to an organization yet.');

        $organization = $this->organizations->removeLogo($user, $user->organization);

        return response()->json([
            'data' => $this->organizationPayload($organization, $user),
        ]);
    }

    /**
     * Serve the logo file itself.
     *
     * Reached through a signed URL rather than the bearer token every other
     * route uses, because this one is read by an `<img>` tag that cannot send
     * a header. The signature is the authorization: it is issued only in a
     * payload the member already had the right to fetch.
     */
    public function logo(Organization $organization): mixed
    {
        abort_if($organization->logo_path === null, 404);

        $disk = Storage::disk(OrganizationService::LOGO_DISK);

        abort_unless($disk->exists($organization->logo_path), 404);

        return $disk->response($organization->logo_path, null, [
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * List the members of the user's organization.
     */
    public function members(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        abort_unless($user->hasActiveMembership(), 403);

        $members = $user->organization->users()
            ->with('organization')
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
     * Leave the organization the signed-in user belongs to.
     */
    public function leave(Request $request): JsonResponse
    {
        $user = $request->user();

        $this->organizations->leave($user);

        return response()->json(['data' => (new UserResource($user->fresh()))->resolve()]);
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
     * The still-valid invitations waiting for the authenticated user, matched
     * on their email address rather than on the emailed link.
     *
     * Someone invited to a workspace does not have to pay for one, so the
     * paywall asks here first: an invited user who arrives without their email
     * — or who lost it — is offered the invitation instead of a price list.
     * Nothing to answer once they already belong somewhere, since acceptance
     * would be refused anyway.
     */
    public function pendingInvitations(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        if ($user->organization_id !== null) {
            return InvitationResource::collection([]);
        }

        $invitations = Invitation::query()
            ->active()
            ->whereRaw('lower(email) = ?', [mb_strtolower($user->email)])
            ->with(['organization', 'invitedBy'])
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

        // Checked again on the way in: an organization created on a team plan
        // outlives a downgrade, and its owner must not keep growing it on a
        // plan that no longer carries seats.
        PlanFeatures::ensureHas($user, PlanFeatures::TEAMS);

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
     * Accept an invitation from the emailed link, which carries the invite
     * token rather than its id (see OrganizationInviteMail: the recipient is
     * sent to `/invite/{token}`).
     *
     * Redeeming used to happen through registration with an `invite_token`.
     * That endpoint is gone now that accounts are created in Supabase, so an
     * invited user signs up there, arrives already authenticated, and posts
     * the token here.
     */
    public function acceptInvitationByToken(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
        ]);

        $invitation = Invitation::query()
            ->where('token', $validated['token'])
            ->first();

        abort_if($invitation === null, 422, 'This invitation link is invalid.');

        return $this->acceptInvitation($request, $invitation);
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
            'description' => $organization->description,
            'website' => $organization->website,
            'logo_url' => $organization->logoUrl(),
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
