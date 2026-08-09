<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DemoRequest;
use App\Services\RecaptchaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DemoRequestController extends Controller
{
    public function __construct(private readonly RecaptchaService $recaptcha) {}

    /**
     * Receive a demo request from the public landing page. The request must
     * pass a reCAPTCHA v3 assessment at or above the configured threshold, and
     * the endpoint is rate limited per IP and per email.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255'],
            'organization' => ['nullable', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:5000'],
            'recaptcha_token' => ['required', 'string'],
        ]);

        $assessment = $this->recaptcha->verify($validated['recaptcha_token'], (string) $request->ip());

        $minScore = (float) config('services.recaptcha.min_score', 0.5);

        abort_unless(
            $assessment['success'] && $assessment['score'] !== null && $assessment['score'] >= $minScore,
            422,
            'We could not verify that you are human. Please try again.',
        );

        $demoRequest = DemoRequest::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'organization' => $validated['organization'] ?? null,
            'message' => $validated['message'] ?? null,
            'status' => DemoRequest::STATUS_PENDING,
            'recaptcha_score' => $assessment['score'],
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 1000),
        ]);

        return response()->json([
            'data' => [
                'id' => $demoRequest->id,
                'status' => $demoRequest->status,
            ],
        ], 201);
    }
}
