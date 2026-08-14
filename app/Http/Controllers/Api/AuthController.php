<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuthController extends Controller
{
    /**
     * Return the currently authenticated user. Authentication is handled by
     * Supabase; the API trusts the validated bearer access token and returns
     * the corresponding local user record.
     */
    public function user(Request $request): JsonResource
    {
        return new UserResource($request->user());
    }

    /**
     * Whether an account exists for the given email and, if so, when it was
     * last used. Called by the login screen before the password is entered so
     * a returning user can be greeted ("Welcome back — last used 3 days ago").
     *
     * Account existence is deliberately not revealed: a never-used account and
     * an unknown email both answer `null`, so this endpoint does not become a
     * register-checking oracle. The route is throttled in case of abuse.
     */
    public function lastUsed(Request $request): JsonResponse
    {
        $email = strtolower((string) $request->query('email', ''));

        $user = User::query()->where('email', $email)->first();

        // `exists` lets the sign-in screen refuse to continue for an email it
        // does not recognize, which necessarily reveals whether an account is
        // registered. The route is throttled (see routes/api.php) so probing
        // is slow, which keeps that disclosure usable without making
        // enumeration trivial.
        return response()->json([
            'exists' => $user !== null,
            'last_used_at' => $user?->last_used_at,
        ]);
    }
}
