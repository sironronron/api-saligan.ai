<?php

namespace App\Http\Controllers\Api;

use App\Enums\DocumentStatus;
use App\Enums\LabelKind;
use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentResource;
use App\Jobs\ProcessDocumentUpload;
use App\Models\Document;
use App\Models\Label;
use App\Models\LegalCase;
use App\Services\Documents\DocumentEncryptor;
use App\Support\PlanLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function __construct(
        private readonly DocumentEncryptor $encryptor,
    ) {
        //
    }

    /**
     * List the authenticated user's uploaded documents, optionally scoped to a
     * case and filtered by the case-file categories they are filed under.
     *
     * A document may sit in several categories at once, so `match` decides how
     * a multi-category filter reads: `any` widens the net, `all` narrows to the
     * documents doing every one of those jobs — the bank records that are both
     * documentary evidence and a financial record.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'case_id' => ['nullable', 'uuid', 'exists:cases,id'],
            'category_id' => ['nullable', 'array'],
            'category_id.*' => ['uuid'],
            'match' => ['nullable', 'in:any,all'],
            'uncategorized' => ['nullable', 'boolean'],
        ]);

        $query = $request->user()->documents()->withCount('chunks')->with('labels');

        if (isset($validated['case_id'])) {
            $case = LegalCase::findOrFail($validated['case_id']);
            abort_unless($case->user_id === $request->user()->id, 403);
            $query->where('case_id', $case->id);
        }

        $categoryIds = $validated['category_id'] ?? [];

        if ($categoryIds !== []) {
            ($validated['match'] ?? 'any') === 'all'
                ? $query->withAllLabels($categoryIds)
                : $query->withAnyLabels($categoryIds);
        }

        if ($request->boolean('uncategorized')) {
            $query->whereDoesntHave('labels');
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
            'label_ids' => ['nullable', 'array'],
            'label_ids.*' => ['uuid'],
        ]);

        $labels = Label::resolveForAssignment(
            $request->user(),
            $validated['label_ids'] ?? [],
            LabelKind::DocumentCategory,
        );

        if (isset($validated['case_id'])) {
            $case = LegalCase::findOrFail($validated['case_id']);
            abort_unless($case->user_id === $request->user()->id, 403);
        }

        PlanLimits::ensureCanUse($request->user(), 'documents_uploaded');

        $file = $validated['file'];

        $originalFilename = $file->getClientOriginalName();

        $storagePath = 'documents/'.Str::uuid().'.'.($file->getClientOriginalExtension() ?: 'bin');

        if (config('saligan.documents.encrypt_at_rest', true)) {
            $this->encryptor->encrypt((string) ($file->getRealPath() ?: $file->getPathname()), $storagePath);
        } else {
            $file->storeAs('documents', basename($storagePath), 'local');
        }

        $document = Document::create([
            'user_id' => $request->user()->id,
            'case_id' => $validated['case_id'] ?? null,
            'title' => $validated['title'] ?? pathinfo($originalFilename, PATHINFO_FILENAME),
            'original_filename' => $originalFilename,
            'storage_path' => $storagePath,
            'mime_type' => $file->getClientMimeType(),
            'status' => DocumentStatus::Queued,
        ]);

        if ($labels->isNotEmpty()) {
            $document->syncLabels($labels, $request->user());
        }

        PlanLimits::increment($request->user(), 'documents_uploaded');

        ProcessDocumentUpload::dispatch($document)
            ->onQueue(config('saligan.documents.queue'));

        return (new DocumentResource($document->load('labels')))->response()->setStatusCode(201);
    }

    /**
     * Show a single document.
     */
    public function show(Request $request, Document $document): DocumentResource
    {
        abort_unless($document->user_id === $request->user()->id, 403);

        return new DocumentResource($document->load('labels')->loadCount('chunks'));
    }

    /**
     * Rename a document and file it under case-file categories.
     *
     * Categories are replaced wholesale rather than merged: the picker sends
     * the full set it is showing, so a category the user cleared has to come
     * back off the document.
     */
    public function update(Request $request, Document $document): DocumentResource
    {
        abort_unless($document->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'label_ids' => ['sometimes', 'array'],
            'label_ids.*' => ['uuid'],
        ]);

        if (array_key_exists('title', $validated)) {
            $document->update(['title' => $validated['title']]);
        }

        if (array_key_exists('label_ids', $validated)) {
            $document->syncLabels(
                Label::resolveForAssignment($request->user(), $validated['label_ids'], LabelKind::DocumentCategory),
                $request->user(),
            );
        }

        return new DocumentResource($document->load('labels')->loadCount('chunks'));
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
     * Serve the stored file to its owner. PDFs, images, and plain-text files
     * are served inline so they can be previewed in the browser; everything
     * else is served as an attachment. Clients may force either disposition
     * via the `disposition` query parameter.
     */
    public function file(Request $request, Document $document): StreamedResponse
    {
        abort_unless($document->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'disposition' => ['nullable', 'in:inline,attachment'],
        ]);

        if (! Storage::exists($document->storage_path)) {
            abort(404, 'The file is no longer available.');
        }

        $mimeType = $document->mime_type ?: 'application/octet-stream';

        $disposition = $validated['disposition'] ?? $this->defaultDisposition($mimeType);

        if ($this->encryptor->isEncrypted($document->storage_path)) {
            return $this->streamDecryptedFile(
                $document->storage_path,
                $document->original_filename,
                $mimeType,
                $disposition,
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
            $disposition,
        );
    }

    /**
     * Stream an encrypted stored document after decrypting it on the fly, so
     * the plaintext never touches the disk during a download.
     */
    protected function streamDecryptedFile(string $storagePath, string $filename, string $mimeType, string $disposition): StreamedResponse
    {
        return response()->streamDownload(
            function () use ($storagePath): void {
                foreach ($this->encryptor->decryptStream($storagePath) as $chunk) {
                    echo $chunk;
                }
            },
            $filename,
            [
                'Content-Type' => $mimeType,
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
            $disposition,
        );
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

    /**
     * Whether the browser can render the MIME type inline.
     */
    private function isViewable(string $mimeType): bool
    {
        return in_array($mimeType, ['application/pdf', 'text/plain', 'text/markdown', 'text/md'], true)
            || str_starts_with($mimeType, 'image/');
    }

    /**
     * Serve viewable types inline, everything else as a download.
     */
    private function defaultDisposition(string $mimeType): string
    {
        return $this->isViewable($mimeType) ? 'inline' : 'attachment';
    }
}
