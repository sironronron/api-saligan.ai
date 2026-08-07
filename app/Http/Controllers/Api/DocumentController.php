<?php

namespace App\Http\Controllers\Api;

use App\Enums\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentResource;
use App\Jobs\ProcessDocumentUpload;
use App\Models\Document;
use App\Models\LegalCase;
use App\Support\PlanLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class DocumentController extends Controller
{
    /**
     * List the authenticated user's uploaded documents, optionally scoped to a case.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'case_id' => ['nullable', 'uuid', 'exists:cases,id'],
        ]);

        $query = $request->user()->documents()->withCount('chunks');

        if (isset($validated['case_id'])) {
            $case = LegalCase::findOrFail($validated['case_id']);
            abort_unless($case->user_id === $request->user()->id, 403);
            $query->where('case_id', $case->id);
        }

        return DocumentResource::collection($query->latest()->get());
    }

    /**
     * Store an uploaded document and queue it for ingestion.
     */
    public function store(Request $request): JsonResponse
    {
        $maxKb = config('saligan.documents.max_size_mb') * 1024;

        $validated = $request->validate([
            'file' => [
                'required',
                'file',
                "max:{$maxKb}",
                Rule::file()->extensions(array_merge(
                    ['pdf', 'docx', 'txt', 'md'],
                    config('saligan.documents.image_extensions', []),
                )),
            ],
            'title' => ['nullable', 'string', 'max:255'],
            'case_id' => ['nullable', 'uuid', 'exists:cases,id'],
        ]);

        if (isset($validated['case_id'])) {
            $case = LegalCase::findOrFail($validated['case_id']);
            abort_unless($case->user_id === $request->user()->id, 403);
        }

        PlanLimits::ensureCanUse($request->user(), 'documents_uploaded');

        $file = $validated['file'];

        $originalFilename = $file->getClientOriginalName();

        $document = Document::create([
            'user_id' => $request->user()->id,
            'case_id' => $validated['case_id'] ?? null,
            'title' => $validated['title'] ?? pathinfo($originalFilename, PATHINFO_FILENAME),
            'original_filename' => $originalFilename,
            'storage_path' => $file->store('documents', 'local'),
            'mime_type' => $file->getClientMimeType(),
            'status' => DocumentStatus::Queued,
        ]);

        PlanLimits::increment($request->user(), 'documents_uploaded');

        ProcessDocumentUpload::dispatch($document)
            ->onQueue(config('saligan.documents.queue'));

        return (new DocumentResource($document))->response()->setStatusCode(201);
    }

    /**
     * Show a single document.
     */
    public function show(Request $request, Document $document): DocumentResource
    {
        abort_unless($document->user_id === $request->user()->id, 403);

        return new DocumentResource($document->loadCount('chunks'));
    }

    /**
     * Attach an existing document to one of the authenticated user's cases so
     * it becomes retrievable within that case's conversations.
     */
    public function attach(Request $request, Document $document): DocumentResource
    {
        abort_unless($document->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'case_id' => ['required', 'uuid', 'exists:cases,id'],
        ]);

        $case = LegalCase::findOrFail($validated['case_id']);
        abort_unless($case->user_id === $request->user()->id, 403);

        $document->update(['case_id' => $case->id]);

        return new DocumentResource($document->loadCount('chunks'));
    }

    /**
     * Delete a document and its chunks (cascaded).
     */
    public function destroy(Request $request, Document $document): JsonResponse
    {
        abort_unless($document->user_id === $request->user()->id, 403);

        Storage::delete($document->storage_path);

        $document->delete();

        return response()->json(null, 204);
    }
}
