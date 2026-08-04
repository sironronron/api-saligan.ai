<?php

namespace App\Http\Controllers\Api;

use App\Enums\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentResource;
use App\Jobs\ProcessDocumentUpload;
use App\Models\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class DocumentController extends Controller
{
    /**
     * List the authenticated user's uploaded documents.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return DocumentResource::collection(
            $request->user()->documents()
                ->withCount('chunks')
                ->latest()
                ->get(),
        );
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
                Rule::file()->extensions(['pdf', 'docx', 'txt', 'md']),
            ],
            'title' => ['nullable', 'string', 'max:255'],
        ]);

        $file = $validated['file'];

        $originalFilename = $file->getClientOriginalName();

        $document = Document::create([
            'user_id' => $request->user()->id,
            'title' => $validated['title'] ?? pathinfo($originalFilename, PATHINFO_FILENAME),
            'original_filename' => $originalFilename,
            'storage_path' => $file->store('documents', 'local'),
            'mime_type' => $file->getClientMimeType(),
            'status' => DocumentStatus::Queued,
        ]);

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
