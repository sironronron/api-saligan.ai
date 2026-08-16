<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\CrawlStatus;
use App\Enums\LegalSourceCategory;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessLegalDocumentUpload;
use App\Models\CrawledPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LegalDocumentController extends Controller
{
    /**
     * List admin-uploaded legal documents, optionally filtered by status.
     */
    public function index(Request $request): JsonResponse
    {
        $documents = CrawledPage::query()
            ->where('kind', CrawledPage::KIND_UPLOADED)
            ->withCount('chunks')
            ->when($request->filled('status'), fn ($query) => $query->where('crawl_status', $request->string('status')))
            ->latest('created_at')
            ->paginate($request->integer('per_page', 25));

        return response()->json($documents);
    }

    /**
     * Accept an uploaded legal document, store it, and queue it for indexing.
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
            'law_name' => ['nullable', 'string', 'max:255'],
            'gr_number' => ['nullable', 'string', 'max:255'],
            'promulgation_date' => ['nullable', 'date'],
            'category' => ['required', Rule::enum(LegalSourceCategory::class)],
        ]);

        $file = $validated['file'];
        $originalFilename = $file->getClientOriginalName();

        $storagePath = 'legal-uploads/'.Str::uuid().'.'.($file->getClientOriginalExtension() ?: 'bin');
        $file->storeAs(dirname($storagePath), basename($storagePath));

        $page = CrawledPage::create([
            'kind' => CrawledPage::KIND_UPLOADED,
            'category' => $validated['category'],
            'storage_path' => $storagePath,
            'original_filename' => $originalFilename,
            'mime_type' => $file->getClientMimeType(),
            'title' => $validated['title'] ?? pathinfo($originalFilename, PATHINFO_FILENAME),
            'law_name' => $validated['law_name'] ?? null,
            'gr_number' => $validated['gr_number'] ?? null,
            'promulgation_date' => $validated['promulgation_date'] ?? null,
            'crawl_status' => CrawlStatus::Pending->value,
        ]);

        ProcessLegalDocumentUpload::dispatch($page);

        return response()->json($page->loadCount('chunks'), 201);
    }

    /**
     * Stream an uploaded document's original file back to an admin.
     */
    public function file(Request $request, CrawledPage $crawledPage): StreamedResponse
    {
        abort_unless($crawledPage->isUploaded(), 404);
        abort_unless($crawledPage->storage_path !== null && Storage::exists($crawledPage->storage_path), 404, 'The file is no longer available.');

        // Mapped from the stored extension rather than taken from `mime_type`,
        // which is whatever the uploading client claimed. Serving that back
        // verbatim and inline lets an upload choose the Content-Type it renders
        // under in the admin's own origin.
        $mimeType = match (strtolower(pathinfo((string) $crawledPage->storage_path, PATHINFO_EXTENSION))) {
            'pdf' => 'application/pdf',
            'txt' => 'text/plain',
            'md' => 'text/markdown',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            default => 'application/octet-stream',
        };

        return Storage::response(
            $crawledPage->storage_path,
            $crawledPage->original_filename,
            [
                'Content-Type' => $mimeType,
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
            $mimeType === 'application/octet-stream' ? 'attachment' : 'inline',
        );
    }

    /**
     * Delete an uploaded document, its file, and its indexed chunks.
     */
    public function destroy(Request $request, CrawledPage $crawledPage): JsonResponse
    {
        abort_unless($crawledPage->isUploaded(), 404);

        if ($crawledPage->storage_path !== null) {
            Storage::delete($crawledPage->storage_path);
        }

        $crawledPage->delete();

        return response()->json(null, 204);
    }
}
