<?php

namespace App\Providers;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Log::debug('[AppServiceProvider] Registering ApiUserProvider for runtime auth');

        Auth::provider('api_users', function ($app, $config) {
            return new \App\Auth\ApiUserProvider();
        });

        // The auth driver decision is now purely runtime:
        // When online, the ApiUserProvider fetches from the remote API.
        // When offline, the EloquentUserProvider falls back to local SQLite.
        // Both providers are available; the NetworkStatusService inside the repos
        // determines which data source to use at query time.
        config(['auth.providers.users.driver' => 'api_users']);
    }
}
