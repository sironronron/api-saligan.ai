<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrackLastUsed
{
    /**
     * How often the timestamp may change before another write is needed.
     */
    private const MIN_INTERVAL_SECONDS = 300;

    /**
     * Stamp the authenticated user's last-used timestamp on every API call,
     * but only write when it actually changes: a returning user's login screen
     * reads the previous value, and the timestamp otherwise grows stale by a
     * minute or two rather than remaining accurate to the second.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // The profile fetch runs on every page load (and on the OAuth callback
        // after a Google sign-in), so stamping it would advance the timestamp
        // to "now" before the login screens could ever read the previous value
        // to greet a returning user. Real usage — conversations, cases,
        // documents, and the rest — is what "last used" should mean.
        if ($request->routeIs('user')) {
            return $response;
        }

        $user = $request->user();

        if ($user !== null && $user->getAuthIdentifier() !== null) {
            if ($user->last_used_at === null
                || $user->last_used_at->isBefore(now()->subSeconds(self::MIN_INTERVAL_SECONDS))) {
                $user->markUsed();
            }
        }

        return $response;
    }
}
