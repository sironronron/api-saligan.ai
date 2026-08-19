<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TaskComment;
use App\Models\Todo;
use App\Models\User;
use App\Notifications\TaskCommented;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskCommentController extends Controller
{
    public function index(Request $request, Todo $todo): JsonResponse
    {
        $this->authorizeTodo($todo, $request);

        $comments = $todo->comments()->with('user:id,name,email')->get();

        return response()->json(['data' => $comments]);
    }

    public function store(Request $request, Todo $todo): JsonResponse
    {
        $this->authorizeTodo($todo, $request);

        $validated = $request->validate([
            'body' => 'required|string|max:5000',
        ]);

        $comment = $todo->comments()->create([
            'user_id' => $request->user()->id,
            'body' => $validated['body'],
        ]);

        $this->notifyAssignee($todo, $comment, $request->user());

        $comment->load('user:id,name,email');

        return response()->json(['data' => $comment], 201);
    }

    public function destroy(Request $request, Todo $todo, TaskComment $comment): JsonResponse
    {
        $this->authorizeTodo($todo, $request);

        abort_unless($comment->user_id === $request->user()->id, 403);

        $comment->delete();

        return response()->json(['message' => 'Comment deleted']);
    }

    private function authorizeTodo(Todo $todo, Request $request): void
    {
        $conversation = $todo->conversation;
        abort_unless($conversation && $conversation->isAccessibleBy($request->user()), 403);
    }

    /**
     * Resolve the assignee's display name to a member of the todo's
     * organization, if one exists.
     */
    protected function resolveAssigneeUser(Todo $todo): ?User
    {
        if (blank($todo->assignee)) {
            return null;
        }

        $owner = $todo->conversation?->user;

        if ($owner === null || $owner->organization_id === null) {
            return null;
        }

        return User::query()
            ->where('organization_id', $owner->organization_id)
            ->where('org_status', User::ORG_STATUS_ACTIVE)
            ->where('name', $todo->assignee)
            ->first();
    }

    /**
     * Notify the assignee about the comment, unless they wrote it themselves.
     */
    protected function notifyAssignee(Todo $todo, TaskComment $comment, User $commenter): void
    {
        if (blank($todo->assignee)) {
            return;
        }

        $assignee = $this->resolveAssigneeUser($todo);

        if ($assignee !== null && $assignee->id !== $commenter->id) {
            $assignee->notify(new TaskCommented($todo, $comment, $commenter));
        }
    }
}
