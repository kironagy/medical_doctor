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
        $nativeAppId = env('NATIVEPHP_APP_ID');
        Log::debug('[AppServiceProvider] NATIVEPHP_APP_ID=' . ($nativeAppId ?? 'null'));

        if ($nativeAppId) {
            Log::debug('[AppServiceProvider] Registering ApiUserProvider and switching auth driver');
            Auth::provider('api_users', function ($app, $config) {
                return new \App\Auth\ApiUserProvider();
            });

            config(['auth.providers.users.driver' => 'api_users']);
            Log::debug('[AppServiceProvider] Auth driver switched to: ' . config('auth.providers.users.driver'));
        }
    }
}
