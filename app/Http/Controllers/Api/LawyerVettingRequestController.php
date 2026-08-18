<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\VettingRequestResource;
use App\Models\VettingRequest;
use App\Services\Vetting\VettingRequestService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * The lawyer-side workspace: the requests they have been offered or hold, and
 * the accept/decline/status/session actions on them.
 */
class LawyerVettingRequestController extends Controller
{
    public function __construct(private readonly VettingRequestService $service)
    {
        //
    }

    /**
     * The requests offered to or held by the authenticated lawyer.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $query = VettingRequest::query()
            ->where(function ($query) use ($user) {
                $query->where('assigned_lawyer_id', $user->id)
                    ->orWhereHas('matches', fn ($q) => $q->where('lawyer_id', $user->id));
            })
            ->with(['submitter', 'document', 'assignedLawyer'])
            ->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return VettingRequestResource::collection($query->paginate(20));
    }

    /**
     * A request the lawyer has been offered or holds.
     */
    public function show(Request $request, VettingRequest $vettingRequest): VettingRequestResource
    {
        abort_unless($vettingRequest->isAccessibleBy($request->user()), 403);

        return new VettingRequestResource(
            $vettingRequest->load(['submitter', 'document', 'assignedLawyer']),
        );
    }

    /**
     * Accept the request and lock the assignment.
     */
    public function accept(Request $request, VettingRequest $vettingRequest): VettingRequestResource
    {
        return new VettingRequestResource(
            $this->service->accept($request->user(), $vettingRequest)
                ->load(['submitter', 'document', 'assignedLawyer']),
        );
    }

    /**
     * Decline the offer.
     */
    public function decline(Request $request, VettingRequest $vettingRequest): VettingRequestResource
    {
        return new VettingRequestResource(
            $this->service->decline($request->user(), $vettingRequest)
                ->load(['submitter', 'document', 'assignedLawyer']),
        );
    }

    /**
     * Advance an accepted request to under review or vetted.
     */
    public function markStatus(Request $request, VettingRequest $vettingRequest): VettingRequestResource
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['under_review', 'vetted'])],
        ]);

        return new VettingRequestResource(
            $this->service->markStatus($request->user(), $vettingRequest, $validated['status'])
                ->load(['submitter', 'document', 'assignedLawyer']),
        );
    }

    /**
     * Schedule the live video session for a notarization.
     */
    public function schedule(Request $request, VettingRequest $vettingRequest): VettingRequestResource
    {
        $validated = $request->validate([
            'scheduled_at' => ['sometimes', 'nullable', 'date', 'after:now'],
            'session_link' => ['sometimes', 'nullable', 'url', 'max:500'],
        ]);

        return new VettingRequestResource(
            $this->service->scheduleSession($request->user(), $vettingRequest, $validated)
                ->load(['submitter', 'document', 'assignedLawyer']),
        );
    }

    /**
     * Complete the notarization after the live session.
     */
    public function notarize(Request $request, VettingRequest $vettingRequest): VettingRequestResource
    {
        $validated = $request->validate([
            'signer_name' => ['required', 'string', 'max:255'],
            'id_type' => ['required', 'string', 'max:100'],
            'id_number' => ['required', 'string', 'max:150'],
            'session_recording_ref' => ['sometimes', 'nullable', 'string', 'max:255'],
            'verification_method' => ['sometimes', 'nullable', 'string', 'max:100'],
            'metadata' => ['sometimes', 'array'],
        ]);

        return new VettingRequestResource(
            $this->service->completeNotarization($request->user(), $vettingRequest, $validated)
                ->load(['submitter', 'document', 'assignedLawyer', 'journalEntry']),
        );
    }
}
