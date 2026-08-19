<?php

namespace App\Services\Vetting;

use App\Enums\DocumentStatus;
use App\Enums\LawyerVerificationStatus;
use App\Enums\VettingMatchStatus;
use App\Enums\VettingPaymentStatus;
use App\Enums\VettingRequestStatus;
use App\Enums\VettingServiceType;
use App\Jobs\EscalateVettingRequest;
use App\Models\Document;
use App\Models\User;
use App\Models\VettingMessage;
use App\Models\VettingRequest;
use App\Models\VettingRequestMatch;
use App\Notifications\NewVettingMessage;
use App\Notifications\NewVettingRequest;
use App\Notifications\NotarizationScheduled;
use App\Notifications\VettingRequestAccepted;
use App\Notifications\VettingRequestCancelled;
use App\Notifications\VettingRequestStatusChanged;
use App\Notifications\VettingRequestWaiting;
use App\Services\Billing\VettingPaymentService;
use App\Services\Documents\DocumentEncryptor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The workflow behind a vetting/notarization request: creation, lawyer
 * matching and notification, accept/decline, escalation on no response, status
 * transitions, session scheduling, and the final notarization.
 */
final class VettingRequestService
{
    public function __construct(
        private readonly LawyerMatcher $matcher,
        private readonly VettingFees $fees,
        private readonly VettingSettings $settings,
        private readonly VettingPaymentService $payments,
        private readonly DocumentEncryptor $encryptor,
    ) {
        //
    }

    /**
     * Create a vetting request, storing the document, computing fees, and
     * starting the matching flow. When the request carries a fee, the buyer is
     * sent to PayMongo to authorize it before any lawyer is contacted.
     *
     * @param  array<string, mixed>  $data
     * @return array{request: VettingRequest, checkout_url: string|null}
     */
    public function create(User $submitter, array $data, ?UploadedFile $file, ?Document $document): array
    {
        $serviceType = VettingServiceType::from($data['service_type']);

        if ($document === null && $file !== null) {
            $document = $this->storeDocument($submitter, $file);
        }

        abort_if($document === null, 422, 'A document is required for vetting.');

        abort_unless($document->isAccessibleBy($submitter), 403);

        $propertyValue = isset($data['property_value']) && $data['property_value'] !== null && $data['property_value'] !== ''
            ? (int) round((float) $data['property_value'] * 100)
            : null;

        $breakdown = $this->fees->compute($serviceType->value, $data['document_type'], $propertyValue);

        $request = VettingRequest::create([
            'submitter_id' => $submitter->id,
            'document_id' => $document->id,
            'document_type' => $data['document_type'],
            'summary' => $data['summary'],
            'jurisdiction' => $data['jurisdiction'] ?? null,
            'service_type' => $serviceType,
            'urgency' => $data['urgency'] ?? 'normal',
            'status' => VettingRequestStatus::Pending,
            'vetting_fee' => $breakdown['vetting_fee'] > 0 ? $breakdown['vetting_fee'] : null,
            'notarization_fee' => $breakdown['notarization_fee'] > 0 ? $breakdown['notarization_fee'] : null,
            'property_value' => $propertyValue,
            'processing_fee' => $breakdown['processing_fee'] > 0 ? $breakdown['processing_fee'] : null,
            'payment_status' => VettingPaymentStatus::None,
            'deadline_at' => $data['deadline_at'] ?? null,
            'letter_draft_message_id' => $data['letter_draft_message_id'] ?? null,
        ]);

        if ($request->requiresPayment()) {
            $checkout = $this->payments->authorize($request, $submitter);

            $request->update(['status' => VettingRequestStatus::PaymentPending]);

            return [
                'request' => $request,
                'checkout_url' => $checkout['checkout_url'],
            ];
        }

        $this->startMatching($request);

        return ['request' => $request, 'checkout_url' => null];
    }

    /**
     * Start matching a request after its payment has been authorized. No-op
     * when the request is already locked or finished.
     */
    public function startMatching(VettingRequest $request): void
    {
        if (! in_array($request->status, [
            VettingRequestStatus::Pending,
            VettingRequestStatus::PaymentPending,
            VettingRequestStatus::Matched,
            VettingRequestStatus::Waiting,
        ], true)) {
            return;
        }

        $this->notifyNextPool($request);
    }

    /**
     * Offer the request to the next pool of eligible lawyers. Returns whether
     * anyone was notified.
     */
    public function notifyNextPool(VettingRequest $request): bool
    {
        if (! $request->isOpen() || $request->assigned_lawyer_id !== null) {
            return false;
        }

        $candidates = $this->matcher->candidates($request)->take($this->settings->matchPoolSize());

        if ($candidates->isEmpty()) {
            $this->waitForLawyer($request);

            return false;
        }

        $expiresAt = now()->addHours($this->settings->escalationHours());

        foreach ($candidates as $lawyer) {
            VettingRequestMatch::create([
                'vetting_request_id' => $request->id,
                'lawyer_id' => $lawyer->id,
                'status' => VettingMatchStatus::Notified,
                'notified_at' => now(),
                'expires_at' => $expiresAt,
            ]);

            $lawyer->notify(new NewVettingRequest($request));
        }

        $request->update(['status' => VettingRequestStatus::Matched]);

        EscalateVettingRequest::dispatch($request)
            ->delay(now()->addHours($this->settings->escalationHours()));

        return true;
    }

    /**
     * A lawyer accepts the request. The assignment is locked to them: any
     * other outstanding offers are escalated out, so no second lawyer can take
     * the same document.
     */
    public function accept(User $lawyer, VettingRequest $request): VettingRequest
    {
        $lock = Cache::lock("vetting.accept.{$request->id}", 10);

        if (! $lock->get()) {
            abort(409, 'Another lawyer is accepting this request right now.');
        }

        try {
            abort_unless($request->isOpen(), 422, 'This request is no longer open.');
            abort_if($request->assigned_lawyer_id !== null, 422, 'This request is already assigned.');

            $match = $request->matches()->where('lawyer_id', $lawyer->id)->first();

            abort_if($match === null, 403, 'You have not been offered this request.');

            if ($request->includesNotarization() && ! $this->lawyerCanNotarize($lawyer)) {
                abort(422, 'Your notarial commission is not active for this request.');
            }

            $request->update([
                'status' => VettingRequestStatus::Accepted,
                'assigned_lawyer_id' => $lawyer->id,
                'locked_at' => now(),
            ]);

            $match->update([
                'status' => VettingMatchStatus::Accepted,
                'responded_at' => now(),
            ]);

            $request->matches()
                ->where('status', VettingMatchStatus::Notified)
                ->update(['status' => VettingMatchStatus::Escalated]);

            $request->payments()
                ->where('status', VettingPaymentStatus::Authorized)
                ->update(['lawyer_id' => $lawyer->id]);

            $request->submitter->notify(new VettingRequestAccepted($request));

            return $request->fresh();
        } finally {
            $lock->release();
        }
    }

    /**
     * A lawyer declines the request. When every offered lawyer has declined or
     * timed out, the next pool is notified; if nobody is left, the request is
     * declined and any authorized fee refunded.
     */
    public function decline(User $lawyer, VettingRequest $request): VettingRequest
    {
        abort_unless($request->isOpen(), 422, 'This request is no longer open.');
        abort_if($request->assigned_lawyer_id !== null, 422, 'This request is already assigned.');

        $match = $request->matches()->where('lawyer_id', $lawyer->id)->first();

        abort_if($match === null, 403, 'You have not been offered this request.');

        $match->update([
            'status' => VettingMatchStatus::Declined,
            'responded_at' => now(),
        ]);

        if ($request->matches()->where('status', VettingMatchStatus::Notified)->exists()) {
            return $request->fresh();
        }

        $this->notifyNextPool($request);

        return $request->fresh();
    }

    /**
     * The escalation sweep: expire offers nobody answered, then either notify
     * the next pool or, when every eligible lawyer has already been tried,
     * decline the request and refund the held fee.
     */
    public function escalate(VettingRequest $request): void
    {
        if (! $request->isOpen() || $request->assigned_lawyer_id !== null) {
            return;
        }

        $request->matches()
            ->where('status', VettingMatchStatus::Notified)
            ->where('expires_at', '<=', now())
            ->update(['status' => VettingMatchStatus::Expired]);

        if ($request->matches()->where('status', VettingMatchStatus::Notified)->exists()) {
            return;
        }

        $this->notifyNextPool($request);
    }

    /**
     * The submitter re-runs matching for a request that could not find a
     * lawyer yet. The held fee stays put; a fresh pool is offered whenever any
     * eligible lawyer exists.
     */
    public function retryMatching(User $submitter, VettingRequest $request): VettingRequest
    {
        abort_unless($request->submitter_id === $submitter->id, 403);

        abort_unless(in_array($request->status, [
            VettingRequestStatus::Pending,
            VettingRequestStatus::Waiting,
        ], true), 422, 'This request is not waiting for a lawyer.');

        $this->startMatching($request);

        return $request->fresh();
    }

    /**
     * The submitter cancels a request that has not been accepted yet. A held
     * or captured fee is returned to them.
     */
    public function cancel(User $submitter, VettingRequest $request, ?string $reason): VettingRequest
    {
        abort_unless($request->submitter_id === $submitter->id, 403);

        $cancellable = in_array($request->status, [
            VettingRequestStatus::PaymentPending,
            VettingRequestStatus::Pending,
            VettingRequestStatus::Matched,
            VettingRequestStatus::Waiting,
        ], true);

        abort_unless($cancellable, 422, 'Only an unassigned request can be cancelled.');

        $this->payments->refundOrVoid($request);

        $request->update([
            'status' => VettingRequestStatus::Cancelled,
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
        ]);

        $request->matches()
            ->where('status', VettingMatchStatus::Notified)
            ->update(['status' => VettingMatchStatus::Escalated]);

        $request->matches()
            ->where('status', VettingMatchStatus::Escalated)
            ->with('lawyer')
            ->get()
            ->each(fn (VettingRequestMatch $match) => $match->lawyer?->notify(new VettingRequestCancelled($request)));

        return $request->fresh();
    }

    /**
     * The assigned lawyer advances the request to the next status. Vet-only
     * requests complete when vetted; a request with a notarization leg waits
     * for the notarization step.
     */
    public function markStatus(User $lawyer, VettingRequest $request, string $status): VettingRequest
    {
        $this->assertAssigned($lawyer, $request);

        $next = match ($status) {
            VettingRequestStatus::UnderReview->value => $this->toUnderReview($request),
            VettingRequestStatus::Vetted->value => $this->toVetted($lawyer, $request),
            default => abort(422, 'Unsupported status transition.'),
        };

        return $next;
    }

    /**
     * Schedule the live video session for a notarization. The lawyer picks the
     * slot; the platform attaches a meeting link for the submitter.
     */
    public function scheduleSession(User $lawyer, VettingRequest $request, array $data): VettingRequest
    {
        $this->assertAssigned($lawyer, $request);

        abort_unless($request->includesNotarization(), 422, 'Only notarization requests have sessions.');
        abort_unless($request->status === VettingRequestStatus::Vetted, 422, 'Vet the document before scheduling the session.');

        $link = $data['session_link'] ?? $this->generateSessionLink($request);

        $request->update([
            'session_link' => $link,
            'session_scheduled_at' => $data['scheduled_at'] ?? now(),
            'session_provider' => $data['provider'] ?? config('vetting.session_provider'),
        ]);

        $request->submitter->notify(new NotarizationScheduled($request));

        return $request->fresh();
    }

    /**
     * Complete the notarization after the live session: record the journal
     * entry, capture the fee, and mark the request finished.
     *
     * @param  array<string, mixed>  $data
     */
    public function completeNotarization(User $lawyer, VettingRequest $request, array $data): VettingRequest
    {
        $this->assertAssigned($lawyer, $request);

        abort_unless($request->includesNotarization(), 422, 'This request has no notarization leg.');
        abort_unless($request->status === VettingRequestStatus::Vetted, 422, 'Vet the document before notarizing it.');
        abort_if($request->session_scheduled_at === null, 422, 'Schedule the video session before notarizing.');

        // Capture before recording anything so a failed capture leaves the
        // request vetted for a clean retry instead of half-notarized.
        $this->payments->capture($request);

        $certificateNumber = $request->certificate_number ?? $this->generateCertificateNumber($request);

        $journal = $request->journalEntry()->create([
            'lawyer_id' => $lawyer->id,
            'vetting_request_id' => $request->id,
            'signer_name' => $data['signer_name'],
            'id_type' => $data['id_type'],
            'id_number' => $data['id_number'],
            'document_type' => $request->document_type,
            'verification_method' => $data['verification_method'] ?? config('vetting.verification_method'),
            'certificate_number' => $certificateNumber,
            'session_recording_ref' => $data['session_recording_ref'] ?? null,
            'notarized_at' => now(),
            'metadata' => $data['metadata'] ?? null,
        ]);

        $request->update([
            'status' => VettingRequestStatus::Notarized,
            'certificate_number' => $certificateNumber,
        ]);

        return $this->complete($request);
    }

    /**
     * Finish the request once all its legs are done and tell the submitter.
     */
    public function complete(VettingRequest $request): VettingRequest
    {
        $request->update([
            'status' => VettingRequestStatus::Completed,
            'completed_at' => now(),
        ]);

        $request->submitter->notify(new VettingRequestStatusChanged($request, $request->status));

        return $request->fresh();
    }

    /**
     * Post a message to the clarification thread between submitter and lawyer.
     */
    public function postMessage(User $author, VettingRequest $request, string $body): VettingMessage
    {
        abort_unless($request->participants()->contains($author->id), 403);

        $message = VettingMessage::create([
            'vetting_request_id' => $request->id,
            'author_id' => $author->id,
            'body' => $body,
        ]);

        $request->participants()
            ->reject(fn (int $participantId): bool => $participantId === $author->id)
            ->each(fn (int $participantId): mixed => User::find($participantId)?->notify(new NewVettingMessage($message)));

        return $message;
    }

    /**
     * Whether the lawyer's profile can perform notarizations right now.
     */
    protected function lawyerCanNotarize(User $lawyer): bool
    {
        $profile = $lawyer->lawyerProfile;

        return $profile !== null
            && $profile->verification_status === LawyerVerificationStatus::Verified
            && $profile->canNotarize();
    }

    /**
     * No eligible lawyer was found, so the request stays open and waits for a
     * lawyer to come online. The submitter can retry at any time, and the
     * platform re-runs matching whenever a lawyer turns available.
     */
    protected function waitForLawyer(VettingRequest $request): void
    {
        $request->update(['status' => VettingRequestStatus::Waiting]);

        $request->submitter->notify(new VettingRequestWaiting($request));
    }

    /**
     * Move an accepted request into review.
     */
    protected function toUnderReview(VettingRequest $request): VettingRequest
    {
        abort_unless($request->status === VettingRequestStatus::Accepted, 422, 'Accept the request before starting review.');

        $request->update(['status' => VettingRequestStatus::UnderReview]);

        $request->submitter->notify(new VettingRequestStatusChanged($request, $request->status));

        return $request->fresh();
    }

    /**
     * Finish vetting. A vet-only request completes here; one with a
     * notarization leg holds until the session happens.
     */
    protected function toVetted(User $lawyer, VettingRequest $request): VettingRequest
    {
        abort_unless($request->status === VettingRequestStatus::UnderReview, 422, 'Move the request to under review before finishing vetting.');

        if (! $request->includesNotarization()) {
            // Capture before completing so a failed capture leaves the request
            // under review for a clean retry instead of stuck at vetted.
            $this->payments->capture($request);

            return $this->complete($request);
        }

        $request->update(['status' => VettingRequestStatus::Vetted]);

        $request->submitter->notify(new VettingRequestStatusChanged($request, $request->status));

        return $request->fresh();
    }

    /**
     * Store a freshly uploaded document, encrypted at rest like any other
     * platform document, and mark it ready for a lawyer to open.
     */
    protected function storeDocument(User $submitter, UploadedFile $file): Document
    {
        $storagePath = 'documents/'.Str::uuid().'.'.($file->getClientOriginalExtension() ?: 'bin');

        if (config('saligan.documents.encrypt_at_rest', true)) {
            $this->encryptor->encrypt((string) ($file->getRealPath() ?: $file->getPathname()), $storagePath);
        } else {
            Storage::disk('local')->putFileAs(dirname($storagePath), $file, basename($storagePath));
        }

        return Document::create([
            'user_id' => $submitter->id,
            'title' => $file->getClientOriginalName(),
            'original_filename' => $file->getClientOriginalName(),
            'storage_path' => $storagePath,
            'mime_type' => $file->getClientMimeType(),
            'status' => DocumentStatus::Ready,
        ]);
    }

    /**
     * A Whereby-style meeting link for a notarization session.
     */
    protected function generateSessionLink(VettingRequest $request): string
    {
        return rtrim(config('vetting.session_base_url'), '/')
            .'/'.Str::lower(Str::random(10))
            .'?roomName='.Str::lower(Str::random(6));
    }

    /**
     * A notarial certificate reference, unique enough for a journal entry.
     */
    protected function generateCertificateNumber(VettingRequest $request): string
    {
        return 'BAT-'.now()->format('Ymd').'-'.strtoupper(Str::random(6));
    }

    /**
     * Only the assigned lawyer may advance a request.
     */
    protected function assertAssigned(User $lawyer, VettingRequest $request): void
    {
        abort_unless($request->assigned_lawyer_id === $lawyer->id, 403, 'This request is not assigned to you.');
    }
}
