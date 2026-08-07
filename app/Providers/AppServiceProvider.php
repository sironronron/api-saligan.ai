<?php

namespace App\Providers;

use App\Services\Chat\ChatService;
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
        //
    }
}
