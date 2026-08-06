<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Todo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TodoController extends Controller
{
    /**
     * Display a listing of todos.
     */
    public function index(Request $request): JsonResponse
    {
        $query = $request->user()->todos();

        if ($request->has('conversation_id')) {
            $query->where('conversation_id', $request->input('conversation_id'));
        }

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        $todos = $query->orderBy('order')->orderBy('created_at')->get();

        return response()->json(['data' => $todos]);
    }

    /**
     * Store a newly created todo.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'conversation_id' => ['required', 'uuid', 'exists:conversations,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['sometimes', 'in:pending,on-going,completed'],
            'priority' => ['nullable', 'in:low,medium,high'],
            'due_hint' => ['nullable', 'string', 'max:255'],
            'due_date' => ['nullable', 'date'],
            'assignee' => ['nullable', 'string', 'max:255'],
        ]);

        $conversation = Conversation::findOrFail($validated['conversation_id']);
        abort_unless($conversation->user_id === $request->user()->id, 403);

        $validated['order'] = Todo::query()
            ->where('conversation_id', $conversation->id)
            ->max('order') + 1;

        $todo = Todo::create($validated);

        return response()->json(['data' => $todo], 201);
    }

    /**
     * Update the specified todo.
     */
    public function update(Request $request, Todo $todo): JsonResponse
    {
        abort_unless($todo->conversation->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['sometimes', 'in:pending,on-going,completed'],
            'priority' => ['nullable', 'in:low,medium,high'],
            'due_hint' => ['nullable', 'string', 'max:255'],
            'due_date' => ['nullable', 'date'],
            'assignee' => ['nullable', 'string', 'max:255'],
            'order' => ['nullable', 'integer', 'min:0'],
        ]);

        $todo->update($validated);

        return response()->json(['data' => $todo]);
    }

    /**
     * Persist a new manual ordering for a conversation's todos.
     */
    public function reorder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'conversation_id' => ['required', 'uuid', 'exists:conversations,id'],
            'ordered_ids' => ['required', 'array'],
            'ordered_ids.*' => ['uuid'],
        ]);

        $conversation = Conversation::findOrFail($validated['conversation_id']);
        abort_unless($conversation->user_id === $request->user()->id, 403);

        $todos = $conversation->todos;

        foreach ($validated['ordered_ids'] as $index => $todoId) {
            $todo = $todos->firstWhere('id', $todoId);

            if ($todo === null) {
                continue;
            }

            $todo->update(['order' => $index + 1]);
        }

        return response()->json(['message' => 'Order updated']);
    }

    /**
     * Remove the specified todo.
     */
    public function destroy(Request $request, Todo $todo): JsonResponse
    {
        abort_unless($todo->conversation->user_id === $request->user()->id, 403);

        $todo->delete();

        return response()->json(['message' => 'Todo deleted']);
    }
}
