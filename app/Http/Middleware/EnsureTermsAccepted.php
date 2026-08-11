<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class EnsureTermsAccepted
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Skip terms acceptance check in test environment
        if (App::environment('testing')) {
            return $next($request);
        }

        $user = $request->user();

        if ($user && ! $user->hasAcceptedTerms()) {
            return response()->json([
                'message' => 'You must accept the Terms of Service and Privacy Policy before continuing.',
                'requires_terms_acceptance' => true,
            ], 403);
        }

        return $next($request);
    }
}
