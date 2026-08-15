<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FeedbackController extends Controller
{
    /**
     * Record a thumbs-up / thumbs-down rating for an assistant message so the
     * response can be used to improve the model. Re-submitting clears the
     * previous rating; passing a null body removes it entirely.
     */
    public function store(Request $request, Message $message): JsonResponse
    {
        abort_unless($message->conversation->isAccessibleBy($request->user()), 403);

        $validated = $request->validate([
            'feedback' => ['required', Rule::in(['up', 'down'])],
        ]);

        $message->update([
            'feedback' => $validated['feedback'],
            'feedback_at' => now(),
        ]);

        return response()->json([
            'data' => [
                'id' => $message->id,
                'feedback' => $message->feedback,
                'feedback_at' => $message->feedback_at?->toISOString(),
            ],
        ]);
    }

    /**
     * Clear the feedback previously recorded for a message.
     */
    public function destroy(Request $request, Message $message): JsonResponse
    {
        abort_unless($message->conversation->isAccessibleBy($request->user()), 403);

        $message->update([
            'feedback' => null,
            'feedback_at' => null,
        ]);

        return response()->json([
            'data' => [
                'id' => $message->id,
                'feedback' => null,
                'feedback_at' => null,
            ],
        ]);
    }
}
