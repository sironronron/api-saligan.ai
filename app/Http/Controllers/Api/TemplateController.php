<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TemplateResource;
use App\Models\Template;
use App\Services\Documents\TextExtractor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class TemplateController extends Controller
{
    public function __construct(private readonly TextExtractor $textExtractor) {}

    /**
     * List the system template library plus the user's custom templates.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $templates = Template::query()
            ->where(fn ($query) => $query->whereNull('user_id')->orWhere('user_id', $request->user()->id))
            ->orderByRaw("case when category = 'legal' then 0 when category = 'formal' then 1 when category = 'basic' then 2 else 3 end")
            ->orderBy('name')
            ->get();

        return TemplateResource::collection($templates);
    }

    /**
     * Save a custom template. Accepts pasted content and/or an uploaded
     * template file (PDF, DOCX, TXT, MD); when a file is provided its text is
     * extracted and stored as the template content.
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

            $storagePath = $file->store('template-imports');

            try {
                $extracted = trim($this->textExtractor->extract($storagePath, $mimeType));
            } finally {
                Storage::delete($storagePath);
            }

            if ($extracted === '') {
                return response()->json(['message' => 'No readable text could be extracted from the file.'], 422);
            }

            $content = $extracted;
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
            'placeholder_fields' => $validated['placeholder_fields'] ?? null,
        ]);

        return (new TemplateResource($template))->response()->setStatusCode(201);
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

        return response()->json(null, 204);
    }
}
