<?php

namespace App\Providers;

use App\Services\Chat\ChatService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // ChatService keeps per-request state (the user and assistant message
        // IDs it creates/persists so the controller can roll them back).
        // Scoped resolution guarantees a fresh instance per request and is
        // flushed between requests under Octane, so state can never leak
        // across users. Do not change this to a singleton.
        $this->app->scoped(ChatService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('login', function (Request $request) {
            return [
                // Per-account guard against credential stuffing: a handful of
                // wrong guesses per email+IP. Keep this low enough to slow
                // brute force without blocking a forgetful user.
                Limit::perMinute(5)->by('login.email.'.strtolower($request->input('email', '')).'.'.$request->ip()),
                // Per-IP guard so one client cannot spray many accounts.
                Limit::perMinute(20)->by('login.ip.'.$request->ip()),
            ];
        });

        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(10)->by('auth.'.$request->ip());
        });

        RateLimiter::for('demo-request', function (Request $request) {
            return [
                // Per-IP guard so a single client cannot flood the form.
                Limit::perHour(5)->by('demo-request.ip.'.$request->ip()),
                // Per-email guard so one address cannot generate many leads.
                Limit::perDay(3)->by('demo-request.email.'.strtolower($request->input('email', ''))),
            ];
        });
    }
}
