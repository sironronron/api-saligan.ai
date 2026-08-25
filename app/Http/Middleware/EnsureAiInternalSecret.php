<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAiInternalSecret
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('saligan.ai_provider.internal_secret');
        $authorization = (string) $request->header('Authorization');

        if ($secret === '') {
            return response()->json(['message' => 'AI internal authentication is not configured.'], 503);
        }

        if (! hash_equals('Bearer '.$secret, $authorization)) {
            return response()->json(
                ['message' => 'Invalid internal service credential.'],
                401,
                ['WWW-Authenticate' => 'Bearer'],
            );
        }

        return $next($request);
    }
}
