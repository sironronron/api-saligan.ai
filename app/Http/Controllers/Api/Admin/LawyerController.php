<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\LawyerVerificationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\LawyerProfileResource;
use App\Models\LawyerProfile;
use App\Notifications\LawyerVerificationResult;
use App\Services\Documents\DocumentEncryptor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The admin verification queue: reviewing lawyer registrations, approving or
 * rejecting them, suspending/revoking verification, and reading the credential
 * documents.
 */
class LawyerController extends Controller
{
    public function __construct(private readonly DocumentEncryptor $encryptor)
    {
        //
    }

    /**
     * Every registered lawyer, filterable by verification status and search.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'verification_status' => ['sometimes', 'string', Rule::in(array_column(LawyerVerificationStatus::cases(), 'value'))],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_notary' => ['sometimes', 'boolean'],
        ]);

        $query = LawyerProfile::query()
            ->with('user:id,name,email,created_at')
            ->withCount('activeRequests');

        if (isset($validated['verification_status'])) {
            $query->where('verification_status', $validated['verification_status']);
        }

        if ($request->boolean('is_notary')) {
            $query->where('is_notary', true);
        }

        if (filled($validated['search'] ?? null)) {
            $search = $validated['search'];
            $query->where(function ($query) use ($search) {
                $query->where('full_name', 'like', "%{$search}%")
                    ->orWhere('bar_number', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($q) => $q->where('email', 'like', "%{$search}%"));
            });
        }

        $profiles = $query->latest()->paginate(20);

        return response()->json([
            'data' => LawyerProfileResource::collection($profiles)->resolve(),
            'meta' => [
                'current_page' => $profiles->currentPage(),
                'last_page' => $profiles->lastPage(),
                'total' => $profiles->total(),
                'active_requests_count' => $profiles->sum('active_requests_count'),
            ],
            'filters' => [
                'verification_status' => $validated['verification_status'] ?? null,
            ],
        ]);
    }

    /**
     * One lawyer's full registration record.
     */
    public function show(Request $request, LawyerProfile $lawyerProfile): JsonResponse
    {
        return response()->json([
            'data' => (new LawyerProfileResource(
                $lawyerProfile->load(['user:id,name,email,created_at']),
            ))->resolve(),
            'active_requests_count' => $lawyerProfile->activeRequests()->count(),
        ]);
    }

    /**
     * Approve a lawyer's registration.
     */
    public function approve(Request $request, LawyerProfile $lawyerProfile): JsonResponse
    {
        $this->setStatus($lawyerProfile, LawyerVerificationStatus::Verified);

        $lawyerProfile->user->notify(new LawyerVerificationResult($lawyerProfile->fresh()));

        return $this->updated($lawyerProfile);
    }

    /**
     * Reject a lawyer's registration, with a reason the lawyer can act on.
     */
    public function reject(Request $request, LawyerProfile $lawyerProfile): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $this->setStatus($lawyerProfile, LawyerVerificationStatus::Rejected, $validated['reason']);

        $lawyerProfile->user->notify(new LawyerVerificationResult($lawyerProfile->fresh()));

        return $this->updated($lawyerProfile);
    }

    /**
     * Suspend a verified lawyer (e.g. under investigation or outage).
     */
    public function suspend(Request $request, LawyerProfile $lawyerProfile): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $this->setStatus($lawyerProfile, LawyerVerificationStatus::Suspended, $validated['reason']);

        $lawyerProfile->user->notify(new LawyerVerificationResult($lawyerProfile->fresh()));

        return $this->updated($lawyerProfile);
    }

    /**
     * Revoke a lawyer's verified status permanently.
     */
    public function revoke(Request $request, LawyerProfile $lawyerProfile): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $this->setStatus($lawyerProfile, LawyerVerificationStatus::Revoked, $validated['reason']);

        $lawyerProfile->user->notify(new LawyerVerificationResult($lawyerProfile->fresh()));

        return $this->updated($lawyerProfile);
    }

    /**
     * Re-open a previously rejected/suspended profile for resubmission.
     */
    public function reopen(Request $request, LawyerProfile $lawyerProfile): JsonResponse
    {
        $lawyerProfile->update([
            'verification_status' => LawyerVerificationStatus::Pending,
            'verification_reason' => null,
            'verification_reviewed_at' => null,
        ]);

        return $this->updated($lawyerProfile);
    }

    /**
     * Serve one of the lawyer's credential documents (id or bar membership),
     * decrypted on the fly, to an admin reviewing the application.
     */
    public function document(Request $request, LawyerProfile $lawyerProfile, string $kind): StreamedResponse
    {
        $path = match ($kind) {
            'id_document' => $lawyerProfile->id_document_path,
            'bar_membership_document' => $lawyerProfile->bar_membership_document_path,
            default => abort(404, 'Unknown document kind.'),
        };

        abort_if($path === null, 404, 'This document was not uploaded.');

        if (! Storage::exists($path)) {
            abort(404, 'The file is no longer available.');
        }

        $mimeType = $this->safeMimeType($path);

        if ($this->encryptor->isEncrypted($path)) {
            $chunks = $this->encryptor->decryptStream($path);

            return response()->streamDownload(
                function () use ($chunks): void {
                    foreach ($chunks as $chunk) {
                        echo $chunk;
                    }
                },
                $kind.'.'.pathinfo($path, PATHINFO_EXTENSION),
                [
                    'Content-Type' => $mimeType,
                    'Cache-Control' => 'private, no-store',
                    'X-Content-Type-Options' => 'nosniff',
                ],
                'inline',
            );
        }

        return Storage::response($path, $kind.'.'.pathinfo($path, PATHINFO_EXTENSION), [
            'Content-Type' => $mimeType,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }

    /**
     * Apply a verification status with its audit timestamps.
     */
    protected function setStatus(
        LawyerProfile $lawyerProfile,
        LawyerVerificationStatus $status,
        ?string $reason = null,
    ): void {
        $lawyerProfile->update([
            'verification_status' => $status,
            'verification_reason' => $reason,
            'verification_reviewed_at' => now(),
            'verified_at' => $status === LawyerVerificationStatus::Verified ? now() : $lawyerProfile->verified_at,
            'available' => $status === LawyerVerificationStatus::Verified ? $lawyerProfile->available : false,
        ]);
    }

    protected function updated(LawyerProfile $lawyerProfile): JsonResponse
    {
        return response()->json([
            'data' => (new LawyerProfileResource($lawyerProfile->fresh()))->resolve(),
        ]);
    }

    /**
     * The Content-Type a credential file may be served under.
     */
    private function safeMimeType(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };
    }
}
