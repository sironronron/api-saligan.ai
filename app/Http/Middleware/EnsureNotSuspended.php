<?php

namespace App\Http\Middleware;

use App\Support\SuspendedResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses every authenticated request from a suspended member.
 *
 * This sits on the whole authenticated group rather than beside the
 * subscription gate, because suspension has to hold for the routes that gate
 * deliberately leaves open — notifications, trial redemption, checkout — and
 * for a member whose organization is paid up, whom no billing check would
 * ever stop.
 */
class EnsureNotSuspended
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->isSuspended()) {
            abort(SuspendedResponse::make());
        }

        return $next($request);
    }
}
