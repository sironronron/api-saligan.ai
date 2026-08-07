<?php

namespace App\Http\Middleware;

use App\Support\PlanLimits;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveSubscription
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        PlanLimits::ensureActiveAccess($request->user());

        return $next($request);
    }
}
