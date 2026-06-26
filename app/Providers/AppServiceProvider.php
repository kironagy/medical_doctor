<?php

namespace App\Providers;

use App\Services\OfflineSyncEngine;
use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        \Illuminate\Support\Facades\Gate::define('admin', function ($user) {
            return $user->role === 'admin';
        });

        if (! $this->app->runningInConsole() && config('database.default') === 'sqlite') {
            $this->app->make(OfflineSyncEngine::class)->initializeLocalDatabase();
        }
    }
}
