<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Advisory;
use App\Models\Todo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdvisoryController extends Controller
{
    /**
     * The advisories raised on the user's conversations, newest turn first
     * within a conversation.
     */
    public function index(Request $request): JsonResponse
    {
        $query = $request->user()->advisories();

        if ($request->filled('conversation_id')) {
            $query->where('advisories.conversation_id', $request->input('conversation_id'));
        }

        if ($request->filled('status')) {
            $query->where('advisories.status', $request->input('status'));
        }

        $advisories = $query
            ->orderBy('advisories.created_at')
            ->orderBy('advisories.order')
            ->get();

        return response()->json(['data' => $advisories]);
    }

    /**
     * Record the user's answer to an advisory.
     *
     * Choosing "tracked" files the advisory as a task in the same breath, which
     * is the whole point of the option: an unresolved caveat that needs work
     * belongs on the task list, not in a dialog the user has to remember to
     * reopen. The task is created once — a second "tracked" on the same
     * advisory reuses the one already made.
     */
    public function update(Request $request, Advisory $advisory): JsonResponse
    {
        abort_unless($advisory->conversation?->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'status' => ['required', 'in:'.implode(',', Advisory::STATUSES)],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $status = $validated['status'];

        if ($status === 'tracked' && $advisory->todo_id === null) {
            $todo = Todo::create([
                'conversation_id' => $advisory->conversation_id,
                'title' => $advisory->title,
                'description' => $advisory->detail,
                'status' => 'pending',
                'priority' => $advisory->severity === 'high' ? 'high' : ($advisory->severity === 'low' ? 'low' : 'medium'),
                'order' => Todo::query()->where('conversation_id', $advisory->conversation_id)->max('order') + 1,
            ]);

            $advisory->todo_id = $todo->id;
        }

        $advisory->fill([
            'status' => $status,
            'note' => $validated['note'] ?? $advisory->note,
            // 'open' is the un-answered state, so clearing back to it clears the
            // timestamp too rather than leaving a stale "answered at".
            'responded_at' => $status === 'open' ? null : now(),
        ])->save();

        return response()->json(['data' => $advisory->fresh()]);
    }

    /**
     * Dismiss an advisory outright.
     */
    public function destroy(Request $request, Advisory $advisory): JsonResponse
    {
        abort_unless($advisory->conversation?->user_id === $request->user()->id, 403);

        $advisory->delete();

        return response()->json(null, 204);
    }
}
