<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TemplateResource;
use App\Models\Template;
use App\Models\User;
use App\Services\Documents\TextExtractor;
use App\Services\Templates\DocxTemplateFiller;
use App\Services\Templates\TemplatePlaceholderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class TemplateController extends Controller
{
    public function __construct(
        private readonly TextExtractor $textExtractor,
        private readonly TemplatePlaceholderService $placeholders,
        private readonly DocxTemplateFiller $filler,
    ) {
        //
    }

    /**
     * List the system template library, the user's own templates, and the
     * templates shared by other members of the user's organization.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $templates = Template::query()
            ->visibleTo($request->user())
            ->orderByRaw("case when category = 'legal' then 0 when category = 'formal' then 1 when category = 'basic' then 2 else 3 end")
            ->orderBy('name')
            ->get();

        return TemplateResource::collection($templates);
    }

    /**
     * Save a custom template. Accepts pasted content and/or an uploaded
     * template file. Uploaded .docx files are kept verbatim as the single
     * source of truth for rendering and export: the original archive is stored
     * untouched and later filled by editing its XML in place. The text
     * extracted from it is stored purely for AI analysis (placeholder
     * detection, search indexing) and is never used to regenerate the file.
     * Placeholders are detected as [Bracketed Text] over that extracted text
     * and each must resolve to a clean, matchable token inside the document,
     * otherwise the upload is rejected.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'category' => ['sometimes', 'in:'.implode(',', Template::CATEGORIES)],
            'legal_subtype' => ['nullable', 'string', 'max:60'],
            'content' => ['sometimes', 'string'],
            'template_file' => ['sometimes', 'file', 'max:5120'],
            'structure' => ['nullable', 'array'],
            'structure.*' => ['string', 'max:80'],
            'placeholder_fields' => ['nullable', 'array'],
        ]);

        $content = trim((string) ($validated['content'] ?? ''));
        $file = $request->file('template_file');

        $originalPath = null;
        $mimeType = null;
        $autoDetectedPlaceholders = null;

        if ($file !== null) {
            $extension = strtolower((string) $file->getClientOriginalExtension());

            if (! in_array($extension, ['pdf', 'docx', 'txt', 'md', 'markdown'], true)) {
                return response()->json(['message' => 'Supported template files: PDF, DOCX, TXT, MD.'], 422);
            }

            $mimeType = match ($extension) {
                'pdf' => 'application/pdf',
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                default => 'text/plain',
            };

            $storagePath = $file->store('template-files');

            try {
                $extracted = trim($this->textExtractor->extract(Storage::path($storagePath), $mimeType));
            } catch (\Throwable $e) {
                Storage::delete($storagePath);

                throw $e;
            }

            if ($extension === 'docx') {
                $autoDetectedPlaceholders = $this->placeholders->detect($extracted);
                $unmatchable = $this->placeholders->unMatchable(Storage::path($storagePath), $autoDetectedPlaceholders);

                if ($unmatchable !== []) {
                    Storage::delete($storagePath);

                    return response()->json([
                        'message' => 'These placeholders could not be matched as clean tokens in the document: '.implode(', ', $unmatchable).'. Make sure each [Bracketed Text] placeholder sits in one contiguous run of matching formatting, then re-upload.',
                    ], 422);
                }

                if ($extracted === '') {
                    Storage::delete($storagePath);

                    return response()->json(['message' => 'No readable text could be extracted from the file.'], 422);
                }

                // The uploaded .docx is kept byte-for-byte as the rendering
                // source of truth.
                $originalPath = $storagePath;
                $content = $extracted;
            } else {
                Storage::delete($storagePath);

                if ($extracted === '') {
                    return response()->json(['message' => 'No readable text could be extracted from the file.'], 422);
                }

                $content = $extracted;
            }
        }

        if ($content === '') {
            return response()->json(['message' => 'Provide template content or upload a template file.'], 422);
        }

        $template = $request->user()->templates()->create([
            'name' => $validated['name'],
            'category' => $validated['category'] ?? 'custom',
            'jurisdiction' => 'PH',
            'legal_subtype' => $validated['legal_subtype'] ?? null,
            'content' => $content,
            'structure' => $validated['structure'] ?? null,
            'placeholder_fields' => $autoDetectedPlaceholders ?? $validated['placeholder_fields'] ?? null,
            'original_path' => $originalPath,
            'mime_type' => $mimeType,
        ]);

        return (new TemplateResource($template))->response()->setStatusCode(201);
    }

    /**
     * Fill an uploaded .docx template's placeholders by editing its original
     * document.xml in place, so fonts, logos, layout, and images are untouched.
     * The filled file is streamed back as a new .docx.
     */
    public function fill(Request $request, Template $template): StreamedResponse
    {
        if ($template->isSystem()) {
            throw new NotFoundHttpException('System templates cannot be filled.');
        }

        abort_if(! $template->isDocxFileTemplate(), 422, 'Only uploaded .docx templates can be filled.');

        if (! $template->visibleTo($request->user())) {
            throw new AccessDeniedHttpException;
        }

        $validated = $request->validate([
            'values' => ['required', 'array'],
            'values.*' => ['nullable', 'string'],
        ]);

        $values = array_filter(
            $validated['values'],
            fn (mixed $value): bool => $value !== null && $value !== '',
        );

        $filledPath = $this->filler->fill(Storage::path($template->original_path), $values);

        $filename = $this->sanitizeFilename($template->name).'.docx';

        return response()->streamDownload(function () use ($filledPath) {
            readfile($filledPath);
            @unlink($filledPath);
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ]);
    }

    /**
     * Delete one of the user's own custom templates. System templates cannot be
     * deleted, and templates owned by other users are out of scope.
     */
    public function destroy(Request $request, Template $template): JsonResponse
    {
        if ($template->isSystem()) {
            throw new NotFoundHttpException('System templates cannot be deleted.');
        }

        if ($template->user_id !== $request->user()->id) {
            throw new AccessDeniedHttpException;
        }

        $template->delete();

        if ($template->original_path !== null) {
            Storage::delete($template->original_path);
        }

        return response()->json(null, 204);
    }

    /**
     * A filesystem-safe file name for the filled document.
     */
    protected function sanitizeFilename(string $name): string
    {
        $name = preg_replace('/[^a-zA-Z0-9\s\-_]/', '', $name);
        $name = preg_replace('/\s+/', '_', trim($name));

        return Str::limit($name, 60) ?: 'filled_template';
    }
}
