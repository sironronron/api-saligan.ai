<?php

namespace App\Http\Controllers\Api;

use App\Enums\ChatProvider;
use App\Http\Controllers\Controller;
use App\Http\Resources\ConversationResource;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class ConversationController extends Controller
{
    /**
     * List the authenticated user's conversations.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $conversations = $request->user()->conversations()
            ->withCount('messages')
            ->addSelect([
                'last_message_at' => Message::query()
                    ->select('created_at')
                    ->whereColumn('conversation_id', 'conversations.id')
                    ->latest()
                    ->limit(1),
            ])
            ->latest()
            ->get();

        return ConversationResource::collection($conversations);
    }

    /**
     * Create a new conversation.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'purpose' => ['nullable', 'string', 'max:100'],
            'case_id' => ['nullable', 'uuid', 'exists:cases,id'],
            'provider' => ['nullable', Rule::enum(ChatProvider::class)],
        ]);

        $case = $validated['case_id'] ?? null;

        if ($case !== null) {
            abort_unless($request->user()->cases()->whereKey($case)->exists(), 403);
        }

        $conversation = $request->user()->conversations()->create([
            'title' => $validated['title'] ?? $validated['purpose'] ?? null,
            'purpose' => $validated['purpose'] ?? null,
            'case_id' => $case,
            'provider' => $validated['provider'] ?? ChatProvider::fromConfig(),
        ]);

        return (new ConversationResource($conversation))->response()->setStatusCode(201);
    }

    /**
     * Show a conversation with its messages.
     */
    public function show(Request $request, Conversation $conversation): ConversationResource
    {
        abort_unless($conversation->user_id === $request->user()->id, 403);

        $conversation->load('messages');

        return new ConversationResource($conversation);
    }

    /**
     * Update conversation title.
     */
    public function update(Request $request, Conversation $conversation): ConversationResource
    {
        abort_unless($conversation->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'purpose' => ['nullable', 'string', 'max:100'],
            'provider' => ['nullable', Rule::enum(ChatProvider::class)],
        ]);

        $conversation->update($validated);

        return new ConversationResource($conversation->load('messages'));
    }

    /**
     * Delete a conversation.
     */
    public function destroy(Request $request, Conversation $conversation): JsonResponse
    {
        abort_unless($conversation->user_id === $request->user()->id, 403);

        $conversation->delete();

        return response()->json(null, 204);
    }
}
