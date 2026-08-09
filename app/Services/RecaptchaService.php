<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class RecaptchaService
{
    /**
     * Verify a reCAPTCHA v3 token with Google and return the assessment.
     *
     * Fails closed: a missing secret key or a failed/aborted verification call
     * is treated as a failed check, never a pass.
     *
     * @return array{success: bool, score: float|null, hostname: string|null}
     */
    public function verify(string $token, string $ip): array
    {
        $secretKey = config('services.recaptcha.secret_key');

        if (! filled($secretKey)) {
            return ['success' => false, 'score' => null, 'hostname' => null];
        }

        try {
            $response = Http::asForm()
                ->timeout(10)
                ->post(config('services.recaptcha.verify_url'), [
                    'secret' => $secretKey,
                    'response' => $token,
                    'remoteip' => $ip,
                ])
                ->throw()
                ->json();
        } catch (\Throwable $e) {
            return ['success' => false, 'score' => null, 'hostname' => null];
        }

        return [
            'success' => (bool) ($response['success'] ?? false),
            'score' => isset($response['score']) ? (float) $response['score'] : null,
            'hostname' => $response['hostname'] ?? null,
        ];
    }
}
