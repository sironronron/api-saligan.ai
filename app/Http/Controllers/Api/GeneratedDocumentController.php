<?php

namespace App\Http\Controllers\Api;

use App\Enums\MessageRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\GeneratedDocumentResource;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class GeneratedDocumentController extends Controller
{
    /**
     * List the letters the user drafted in the letter editor, optionally
     * scoped to a single case via the `case_id` query parameter.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        // Every thread the caller may open, not just the ones they started: a
        // draft belongs to the matter it was written for, so an assignee has to
        // see what the rest of the case has produced.
        $conversations = Conversation::query()->visibleTo($request->user());

        if ($request->filled('case_id')) {
            $conversations->where('case_id', $request->string('case_id'));
        }

        $documents = $conversations
            ->with([
                'messages' => fn ($query) => $query
                    ->where('role', MessageRole::Assistant)
                    ->whereNotNull('metadata->letter_draft')
                    ->latest(),
                'messages.conversation.case',
            ])
            ->latest('updated_at')
            ->get()
            ->flatMap->messages
            ->sortByDesc(fn (Message $message) => $message->created_at)
            ->values();

        return GeneratedDocumentResource::collection($documents);
    }

    /**
     * Fetch a single generated document by id, so a draft can be handed to the
     * vetting/notarization flow without re-fetching the whole list.
     */
    public function show(Request $request, Message $message): JsonResource
    {
        abort_unless($message->role === MessageRole::Assistant, 404);
        abort_unless(isset($message->metadata['letter_draft']), 404);
        abort_unless($message->conversation?->isAccessibleBy($request->user()), 403);

        return new GeneratedDocumentResource($message->load(['conversation.case']));
    }

    /**
     * Save edits made to a letter in the editor back onto the assistant
     * message that produced it. The letter body lives in the message metadata
     * as Tiptap JSON, so editing never creates a new message.
     */
    public function saveLetterDraft(Request $request, Message $message): JsonResource
    {
        abort_unless($message->role === MessageRole::Assistant, 404);
        abort_unless($message->conversation?->isAccessibleBy($request->user()), 403);

        $validated = $request->validate([
            'content' => ['required', 'array'],
            'title' => ['nullable', 'string', 'max:255'],
        ]);

        $metadata = $message->metadata ?? [];

        $metadata['letter_draft'] = [
            'content' => $validated['content'],
            'title' => $validated['title'] ?? ($metadata['letter_draft']['title'] ?? null),
            'raw' => $metadata['letter_draft']['raw'] ?? null,
        ];

        $message->update(['metadata' => $metadata]);

        return new GeneratedDocumentResource($message->load(['conversation.case']));
    }
}
