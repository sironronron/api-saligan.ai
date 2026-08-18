<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\TaskActivity;
use App\Models\TaskAttachment;
use App\Models\Todo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskAttachmentController extends Controller
{
    public function index(Request $request, Todo $todo): JsonResponse
    {
        $this->authorizeTodo($todo, $request);

        $attachments = $todo->attachments()
            ->with('document:id,original_filename,mime_type,status')
            ->get();

        return response()->json(['data' => $attachments]);
    }

    public function store(Request $request, Todo $todo): JsonResponse
    {
        $this->authorizeTodo($todo, $request);

        $validated = $request->validate([
            'document_id' => 'required|uuid|exists:documents,id',
        ]);

        $document = Document::findOrFail($validated['document_id']);
        abort_unless($document->isAccessibleBy($request->user()), 403);

        $attachment = $todo->attachments()->firstOrCreate(
            ['todo_id' => $todo->id, 'document_id' => $document->id],
        );

        TaskActivity::create([
            'todo_id' => $todo->id,
            'user_id' => $request->user()->id,
            'type' => 'attachment_added',
            'description' => "attached \"{$document->original_filename}\"",
        ]);

        $attachment->load('document:id,original_filename,mime_type,status');

        return response()->json(['data' => $attachment], 201);
    }

    public function destroy(Request $request, Todo $todo, TaskAttachment $attachment): JsonResponse
    {
        $this->authorizeTodo($todo, $request);

        $filename = $attachment->document?->original_filename ?? 'file';
        $attachment->delete();

        TaskActivity::create([
            'todo_id' => $todo->id,
            'user_id' => $request->user()->id,
            'type' => 'attachment_removed',
            'description' => "removed attachment \"{$filename}\"",
        ]);

        return response()->json(['message' => 'Attachment removed']);
    }

    private function authorizeTodo(Todo $todo, Request $request): void
    {
        $conversation = $todo->conversation;
        abort_unless($conversation && $conversation->isAccessibleBy($request->user()), 403);
    }
}
