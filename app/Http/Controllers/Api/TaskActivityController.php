<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Todo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskActivityController extends Controller
{
    public function index(Request $request, Todo $todo): JsonResponse
    {
        $this->authorizeTodo($todo, $request);

        $activities = $todo->activities()->with('user:id,name,email')->get();

        return response()->json(['data' => $activities]);
    }

    private function authorizeTodo(Todo $todo, Request $request): void
    {
        $conversation = $todo->conversation;
        abort_unless($conversation && $conversation->isAccessibleBy($request->user()), 403);
    }
}
