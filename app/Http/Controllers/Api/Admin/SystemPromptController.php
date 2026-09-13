<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemPrompt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class SystemPromptController extends Controller
{
    /**
     * List all system prompt versions.
     */
    public function index(): AnonymousResourceCollection
    {
        return JsonResource::collection(SystemPrompt::latest()->get());
    }

    /**
     * Create a new system prompt version.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'content' => ['required', 'string'],
        ]);

        $version = (int) (SystemPrompt::where('name', $validated['name'])->max('version') ?? 0) + 1;

        $prompt = SystemPrompt::create([
            'name' => $validated['name'],
            'version' => $version,
            'content' => $validated['content'],
            'is_active' => false,
        ]);

        return response()->json($prompt, 201);
    }

    /**
     * Activate a system prompt, deactivating every version under the Batayan
     * brand, including legacy Saligan rows.
     */
    public function activate(Request $request, SystemPrompt $systemPrompt): JsonResponse
    {
        SystemPrompt::whereIn('name', ['batayan', 'saligan'])
            ->whereKeyNot($systemPrompt->id)
            ->update(['is_active' => false]);

        $systemPrompt->update(['is_active' => true]);

        return response()->json($systemPrompt);
    }
}
