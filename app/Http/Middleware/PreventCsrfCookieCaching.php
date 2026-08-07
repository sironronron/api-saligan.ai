<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Never let a CDN, load balancer, or browser cache the Sanctum CSRF-cookie
 * response. A cached copy is served without the Set-Cookie headers, so the
 * frontend keeps a stale XSRF-TOKEN/session and every write later fails with
 * "CSRF token mismatch".
 */
class PreventCsrfCookieCaching
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->is('sanctum/csrf-cookie')) {
            $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
            $response->headers->set('Pragma', 'no-cache');
            $response->headers->set('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT');
        }

        return $response;
    }
}
