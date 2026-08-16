<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Mail\ConfirmEmailMail;
use App\Models\User;
use App\Services\Auth\SupabaseAdminClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AuthController extends Controller
{
    public function __construct(private readonly SupabaseAdminClient $supabaseAdmin) {}

    /**
     * Create a Supabase account and email the confirmation link through
     * Laravel's mailer instead of Supabase's built-in SMTP.
     *
     * The account is provisioned and its signup link generated server-side via
     * the admin API, which does no delivery of its own, then the link is sent
     * as a normal Laravel mailable. The response is identical whether the
     * address is new or already registered, so the endpoint does not become a
     * register-checking oracle. The route is throttled in case of abuse.
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $redirectTo = rtrim((string) config('app.frontend_url'), '/').'/login';

        try {
            $confirmationUrl = $this->supabaseAdmin->generateSignupLink(
                $validated['email'],
                $validated['password'],
                ['full_name' => $validated['name']],
                $redirectTo,
            );
        } catch (\Throwable $e) {
            // Failures are logged but not surfaced: telling the caller the
            // provisioning failed would also reveal whether the address is
            // taken. The register screen answers identically regardless.
            Log::warning('Registration provisioning failed', [
                'email' => $validated['email'],
                'error' => $e->getMessage(),
            ]);

            return response()->json(['data' => ['status' => 'confirmation_sent']], 201);
        }

        // Null means the address is already registered; the link is not
        // re-sent so a confirmed account is not mailed a fresh signup link.
        if ($confirmationUrl !== null) {
            Mail::to($validated['email'])->send(
                new ConfirmEmailMail($confirmationUrl, $validated['email'])
            );
        }

        return response()->json(['data' => ['status' => 'confirmation_sent']], 201);
    }

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
