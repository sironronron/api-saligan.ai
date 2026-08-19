<?php

namespace App\Http\Controllers\Api;

use App\Enums\MessageRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\LetterCommentResource;
use App\Models\LetterComment;
use App\Models\Message;
use App\Models\User;
use App\Models\VettingRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Comments pinned to blocks of a drafted letter.
 *
 * Access follows the draft: anyone who can open the conversation the draft
 * hangs off may comment, and so may the submitter and assigned lawyer of any
 * vetting/notarization request started from that draft — the lawyer who vets
 * or notarizes the letter should be able to leave notes on the very lines they
 * are reviewing.
 */
class LetterCommentController extends Controller
{
    /**
     * List the comment threads on a draft, grouped by block. Only root comments
     * are returned; each carries its replies nested beneath it.
     */
    public function index(Request $request, Message $message): JsonResponse
    {
        $this->assertLetterDraft($message);
        $this->authorizeMessage($message, $request->user());

        $comments = $message
            ->letterComments()
            ->whereNull('parent_id')
            ->with(['user:id,name,email', 'replies.user:id,name,email'])
            ->oldest()
            ->get();

        return response()->json([
            'data' => LetterCommentResource::collection($comments),
        ]);
    }

    /**
     * Add a comment to a block, or a reply to an existing thread on that block.
     */
    public function store(Request $request, Message $message): JsonResponse
    {
        $this->assertLetterDraft($message);
        $this->authorizeMessage($message, $request->user());

        $validated = $request->validate([
            'block_id' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:5000'],
            'parent_id' => ['sometimes', 'nullable', 'uuid', 'exists:letter_comments,id'],
        ]);

        // A reply must target a root comment on the same draft and block.
        if (! empty($validated['parent_id'])) {
            $parent = LetterComment::findOrFail($validated['parent_id']);

            abort_unless(
                $parent->message_id === $message->id
                    && $parent->block_id === $validated['block_id']
                    && $parent->parent_id === null,
                422,
                'You can only reply to a root comment on the same block.',
            );
        }

        $comment = $message->letterComments()->create([
            'block_id' => $validated['block_id'],
            'parent_id' => $validated['parent_id'] ?? null,
            'user_id' => $request->user()->id,
            'body' => $validated['body'],
        ]);

        $comment->load(['user:id,name,email', 'replies.user:id,name,email']);

        return response()->json([
            'data' => new LetterCommentResource($comment),
        ], 201);
    }

    /**
     * Delete a comment. Only its author may, and deleting a root removes the
     * whole thread beneath it.
     */
    public function destroy(Request $request, Message $message, LetterComment $comment): JsonResponse
    {
        $this->assertLetterDraft($message);
        $this->authorizeMessage($message, $request->user());

        abort_unless($comment->message_id === $message->id, 404);
        abort_unless($comment->user_id === $request->user()->id, 403);

        $comment->delete();

        return response()->json(['message' => 'Comment deleted']);
    }

    /**
     * Whether the caller may interact with comments on this draft: they own or
     * are on the case of the conversation, or they are the submitter or assigned
     * lawyer of a vetting request started from this exact draft.
     */
    /**
     * Comments only attach to messages that actually carry a letter draft;
     * a plain chat message has no document to annotate.
     */
    private function assertLetterDraft(Message $message): void
    {
        abort_unless(
            $message->role === MessageRole::Assistant
                && isset($message->metadata['letter_draft']),
            404,
        );
    }

    private function authorizeMessage(Message $message, User $user): void
    {
        if ($message->conversation && $message->conversation->isAccessibleBy($user)) {
            return;
        }

        $linkedToVetting = VettingRequest::query()
            ->where('letter_draft_message_id', $message->id)
            ->where(fn ($query) => $query
                ->where('submitter_id', $user->id)
                ->orWhere('assigned_lawyer_id', $user->id))
            ->exists();

        abort_unless($linkedToVetting, 403);
    }
}
