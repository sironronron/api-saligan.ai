<?php

namespace App\Http\Controllers\Api;

use App\Enums\VettingServiceType;
use App\Http\Controllers\Controller;
use App\Http\Resources\VettingMessageResource;
use App\Http\Resources\VettingRequestResource;
use App\Models\Document;
use App\Models\VettingRequest;
use App\Services\Documents\DocumentEncryptor;
use App\Services\Vetting\VettingRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File as FileRule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The submitter-facing vetting endpoints plus the message thread and the
 * permissioned document view, which are shared with the assigned lawyer.
 */
class VettingRequestController extends Controller
{
    public function __construct(
        private readonly VettingRequestService $service,
        private readonly DocumentEncryptor $encryptor,
    ) {
        //
    }

    /**
     * Create a vetting/notarization request. A request with a fee returns the
     * PayMongo checkout URL the submitter must complete before matching starts.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'document_type' => ['required', 'string', 'max:100'],
            'summary' => ['required', 'string', 'max:500'],
            'jurisdiction' => ['required', 'string', Rule::in(array_column(config('vetting.regions'), 'value'))],
            'service_type' => ['required', Rule::enum(VettingServiceType::class)],
            'urgency' => ['sometimes', 'in:normal,urgent'],
            'deadline_at' => ['sometimes', 'nullable', 'date', 'after:now'],
            'property_value' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'document_id' => ['required_without:file', 'nullable', 'uuid', 'exists:documents,id'],
            'file' => ['required_without:document_id', 'nullable', 'file', 'max:'.(config('saligan.documents.max_size_mb') * 1024), $this->documentRule()],
        ]);

        $document = isset($validated['document_id'])
            ? Document::findOrFail($validated['document_id'])
            : null;

        $result = $this->service->create(
            $request->user(),
            $validated,
            $request->file('file'),
            $document,
        );

        return response()->json([
            'data' => (new VettingRequestResource(
                $result['request']->load(['submitter', 'document']),
            ))->resolve(),
            'checkout_url' => $result['checkout_url'],
        ], 201);
    }

    /**
     * The submitter's own requests.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return VettingRequestResource::collection(
            $request->user()->vettingRequests()
                ->with(['submitter', 'document', 'assignedLawyer'])
                ->latest()
                ->paginate(20),
        );
    }

    /**
     * A single request, visible to the submitter, the assigned lawyer, any
     * lawyer currently offered it, or an admin.
     */
    public function show(Request $request, VettingRequest $vettingRequest): VettingRequestResource
    {
        abort_unless($vettingRequest->isAccessibleBy($request->user()), 403);

        return new VettingRequestResource(
            $vettingRequest->load(['submitter', 'document', 'assignedLawyer']),
        );
    }

    /**
     * The submitter cancels an unassigned request.
     */
    public function cancel(Request $request, VettingRequest $vettingRequest): VettingRequestResource
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        return new VettingRequestResource(
            $this->service->cancel($request->user(), $vettingRequest, $validated['reason'] ?? null)
                ->load(['submitter', 'document']),
        );
    }

    /**
     * The submitter re-runs matching for a request that is waiting for a lawyer.
     */
    public function retry(Request $request, VettingRequest $vettingRequest): VettingRequestResource
    {
        return new VettingRequestResource(
            $this->service->retryMatching($request->user(), $vettingRequest)
                ->load(['submitter', 'document']),
        );
    }

    /**
     * The clarification thread between the submitter and the lawyer.
     */
    public function messages(Request $request, VettingRequest $vettingRequest): AnonymousResourceCollection
    {
        abort_unless($this->mayMessage($request->user(), $vettingRequest), 403);

        return VettingMessageResource::collection(
            $vettingRequest->messages()->with('author:id,name')->latest()->paginate(50),
        );
    }

    /**
     * Post a message to the clarification thread.
     */
    public function sendMessage(Request $request, VettingRequest $vettingRequest): VettingMessageResource
    {
        abort_unless($this->mayMessage($request->user(), $vettingRequest), 403);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        return new VettingMessageResource(
            $this->service->postMessage($request->user(), $vettingRequest, $validated['body'])
                ->load('author:id,name'),
        );
    }

    /**
     * The permissioned full-document view. Only the submitter, the assigned
     * lawyer, an admin, or (before assignment) an offered lawyer may open it,
     * and only once the request has been accepted.
     */
    public function file(Request $request, VettingRequest $vettingRequest): StreamedResponse
    {
        abort_unless($vettingRequest->isAccessibleBy($request->user()), 403);

        // The full document stays behind the accept gate: a lawyer who was
        // merely offered the request sees the summary, not the contents.
        abort_if(
            $request->user()->id !== $vettingRequest->submitter_id
                && $request->user()->id !== $vettingRequest->assigned_lawyer_id
                && ! $request->user()->is_admin,
            403,
            'Accept the request to view the full document.',
        );

        $document = $vettingRequest->document;

        abort_if($document === null, 404, 'The document is no longer available.');

        if (! Storage::exists($document->storage_path)) {
            abort(404, 'The file is no longer available.');
        }

        $mimeType = $this->safeMimeType($document->storage_path);

        if ($this->encryptor->isEncrypted($document->storage_path)) {
            $chunks = $this->encryptor->decryptStream($document->storage_path);

            return response()->streamDownload(
                function () use ($chunks): void {
                    foreach ($chunks as $chunk) {
                        echo $chunk;
                    }
                },
                $document->original_filename,
                [
                    'Content-Type' => $mimeType,
                    'Cache-Control' => 'private, no-store',
                    'X-Content-Type-Options' => 'nosniff',
                ],
                'inline',
            );
        }

        return Storage::response(
            $document->storage_path,
            $document->original_filename,
            [
                'Content-Type' => $mimeType,
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
            'inline',
        );
    }

    /**
     * The message thread is open to the submitter and the assigned lawyer once
     * the request has been accepted.
     */
    protected function mayMessage($user, VettingRequest $vettingRequest): bool
    {
        if ($user->is_admin) {
            return true;
        }

        if ($vettingRequest->submitter_id !== $user->id && $vettingRequest->assigned_lawyer_id !== $user->id) {
            return false;
        }

        return $vettingRequest->assigned_lawyer_id !== null;
    }

    /**
     * @return FileRule
     */
    protected function documentRule()
    {
        return Rule::file()->extensions(array_merge(
            ['pdf', 'docx', 'txt', 'md'],
            config('saligan.documents.image_extensions', []),
        ));
    }

    /**
     * The Content-Type a stored file may be served under, mapped from its
     * extension, so an uploader cannot pick the type the browser renders.
     */
    private function safeMimeType(string $storagePath): string
    {
        return match (strtolower(pathinfo($storagePath, PATHINFO_EXTENSION))) {
            'pdf' => 'application/pdf',
            'txt' => 'text/plain',
            'md' => 'text/markdown',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'tiff' => 'image/tiff',
            'heic' => 'image/heic',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            default => 'application/octet-stream',
        };
    }
}
