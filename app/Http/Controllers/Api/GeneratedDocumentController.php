<?php

namespace App\Http\Controllers\Api;

use App\Enums\MessageRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\GeneratedDocumentResource;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class GeneratedDocumentController extends Controller
{
    /**
     * List the AI-generated documents the user can download again, optionally
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
                    ->where('content', 'like', '%/export/%')
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
}
