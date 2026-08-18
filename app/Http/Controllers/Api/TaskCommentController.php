<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TaskComment;
use App\Models\Todo;
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
}
