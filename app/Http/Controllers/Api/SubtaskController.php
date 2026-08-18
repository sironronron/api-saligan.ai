<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Subtask;
use App\Models\TaskActivity;
use App\Models\Todo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubtaskController extends Controller
{
    public function index(Request $request, Todo $todo): JsonResponse
    {
        $this->authorizeTodo($todo, $request);

        $subtasks = $todo->subtasks()->get();

        return response()->json(['data' => $subtasks]);
    }

    public function store(Request $request, Todo $todo): JsonResponse
    {
        $this->authorizeTodo($todo, $request);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'order' => 'nullable|integer|min:0',
        ]);

        $subtask = $todo->subtasks()->create([
            'title' => $validated['title'],
            'order' => $validated['order'] ?? ($todo->subtasks()->max('order') + 1),
        ]);

        TaskActivity::create([
            'todo_id' => $todo->id,
            'user_id' => $request->user()->id,
            'type' => 'subtask_added',
            'description' => "added subtask \"{$subtask->title}\"",
        ]);

        return response()->json(['data' => $subtask], 201);
    }

    public function update(Request $request, Todo $todo, Subtask $subtask): JsonResponse
    {
        $this->authorizeTodo($todo, $request);

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'done' => 'sometimes|boolean',
            'order' => 'sometimes|integer|min:0',
        ]);

        $subtask->update($validated);

        if (isset($validated['done'])) {
            TaskActivity::create([
                'todo_id' => $todo->id,
                'user_id' => $request->user()->id,
                'type' => $validated['done'] ? 'subtask_completed' : 'subtask_reopened',
                'description' => $validated['done']
                    ? "completed subtask \"{$subtask->title}\""
                    : "reopened subtask \"{$subtask->title}\"",
            ]);
        }

        return response()->json(['data' => $subtask]);
    }

    public function destroy(Request $request, Todo $todo, Subtask $subtask): JsonResponse
    {
        $this->authorizeTodo($todo, $request);

        $title = $subtask->title;
        $subtask->delete();

        TaskActivity::create([
            'todo_id' => $todo->id,
            'user_id' => $request->user()->id,
            'type' => 'subtask_removed',
            'description' => "removed subtask \"{$title}\"",
        ]);

        return response()->json(['message' => 'Subtask deleted']);
    }

    private function authorizeTodo(Todo $todo, Request $request): void
    {
        $conversation = $todo->conversation;
        abort_unless($conversation && $conversation->isAccessibleBy($request->user()), 403);
    }
}
