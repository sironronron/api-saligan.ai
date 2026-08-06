<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TemplateResource;
use App\Models\Template;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TemplateController extends Controller
{
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
     * Save a custom template derived from an edited letter.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'category' => ['sometimes', 'in:'.implode(',', Template::CATEGORIES)],
            'legal_subtype' => ['nullable', 'string', 'max:60'],
            'content' => ['required', 'string'],
            'structure' => ['nullable', 'array'],
            'structure.*' => ['string', 'max:80'],
            'placeholder_fields' => ['nullable', 'array'],
        ]);

        $template = $request->user()->templates()->create([
            'name' => $validated['name'],
            'category' => $validated['category'] ?? 'custom',
            'jurisdiction' => 'PH',
            'legal_subtype' => $validated['legal_subtype'] ?? null,
            'content' => $validated['content'],
            'structure' => $validated['structure'] ?? null,
            'placeholder_fields' => $validated['placeholder_fields'] ?? null,
        ]);

        return (new TemplateResource($template))->response()->setStatusCode(201);
    }
}
