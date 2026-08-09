<?php

use App\Http\Middleware\EnsureActiveSubscription;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\PreventCsrfCookieCaching;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();

        // The app is served behind nginx on the same host, so requests carry
        // X-Forwarded-* headers. Trusting the local proxy gives accurate
        // client IPs (for rate limiting) and lets HTTPS be detected so the
        // session cookie is flagged Secure. If the box sits behind a remote
        // load balancer or CDN, add that proxy's address here instead.
        $middleware->trustProxies(at: ['127.0.0.1', '::1']);

        $middleware->api(append: [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
        ]);

        $middleware->web(append: [
            PreventCsrfCookieCaching::class,
        ]);

        $middleware->alias([
            'is_admin' => EnsureUserIsAdmin::class,
            'active_subscription' => EnsureActiveSubscription::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
