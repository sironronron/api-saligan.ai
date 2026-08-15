<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LegalCase;
use App\Services\Cases\CaseProgressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CaseProgressController extends Controller
{
    public function __construct(protected CaseProgressService $progress) {}

    /**
     * The whole-of-case progress summary: stage, deadline, counters, threads,
     * tasks, documents, recorded facts, and the full activity timeline.
     */
    public function show(Request $request, LegalCase $case): JsonResponse
    {
        $this->authorize('view', $case);

        return response()->json(['data' => $this->progress->build($case)]);
    }
}
