<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\TaskActivity;
use App\Models\Todo;
use App\Models\User;
use App\Notifications\TaskAssigned;
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
        abort_unless($conversation->isAccessibleBy($request->user()), 403);

        $validated['order'] = Todo::query()
            ->where('conversation_id', $conversation->id)
            ->max('order') + 1;

        $todo = Todo::create($validated);

        TaskActivity::create([
            'todo_id' => $todo->id,
            'user_id' => $request->user()->id,
            'type' => 'todo_created',
            'description' => "created task \"{$todo->title}\"",
        ]);

        $this->notifyAssignee($todo, $request->user());

        return response()->json(['data' => $todo], 201);
    }

    /**
     * Update the specified todo.
     */
    public function update(Request $request, Todo $todo): JsonResponse
    {
        abort_unless($todo->conversation->isAccessibleBy($request->user()), 403);

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

        $originalAssignee = $todo->assignee;

        $todo->update($validated);

        TaskActivity::create([
            'todo_id' => $todo->id,
            'user_id' => $request->user()->id,
            'type' => 'todo_updated',
            'description' => "updated task \"{$todo->title}\"",
        ]);

        if (array_key_exists('assignee', $validated) && $validated['assignee'] !== $originalAssignee) {
            $this->notifyAssignee($todo, $request->user());
        }

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
        abort_unless($conversation->isAccessibleBy($request->user()), 403);

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
        abort_unless($todo->conversation->isAccessibleBy($request->user()), 403);

        $todo->delete();

        return response()->json(['message' => 'Todo deleted']);
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
     * Notify the user the task is assigned to, unless they are the actor.
     */
    protected function notifyAssignee(Todo $todo, User $actor): void
    {
        if (blank($todo->assignee)) {
            return;
        }

        $assignee = $this->resolveAssigneeUser($todo);

        if ($assignee !== null && $assignee->id !== $actor->id) {
            $assignee->notify(new TaskAssigned($todo, $actor));
        }
    }
}
