<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class AuthController extends Controller
{
    /**
     * Register a new user and start an authenticated session.
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        Auth::login($user);

        $request->session()->regenerate();

        return (new UserResource($user))->response()->setStatusCode(201);
    }

    /**
     * Authenticate an existing user and start a session.
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($validated, $request->boolean('remember'))) {
            return response()->json([
                'message' => 'The provided credentials do not match our records.',
            ], 422);
        }

        $request->session()->regenerate();

        return (new UserResource(Auth::user()))->response();
    }

    /**
     * End the authenticated session.
     */
    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return response()->json(null, 204);
    }

    /**
     * Return the currently authenticated user.
     */
    public function user(Request $request): JsonResource
    {
        return new UserResource($request->user());
    }

    /**
     * Send a password reset link to the given email address.
     */
    public function sendPasswordResetLink(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        ResetPassword::createUrlUsing(
            fn ($notifiable, string $token) => sprintf(
                '%s/reset-password?token=%s&email=%s',
                config('app.frontend_url'),
                $token,
                urlencode($notifiable->getEmailForPasswordReset()),
            )
        );

        $status = Password::sendResetLink($request->only('email'));

        // Always respond as if the link was sent so the endpoint does not
        // reveal whether a given email address is registered.
        return response()->json(['message' => 'Password reset link sent.']);
    }

    /**
     * Reset the user's password using a valid token.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $status = Password::reset(
            $validated,
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                ])->setRememberToken(Str::random(60));

                $user->save();
            },
        );

        return $status === Password::PASSWORD_RESET
            ? response()->json(['message' => 'Password has been reset.'])
            : response()->json(['message' => __($status)], 422);
    }
}
