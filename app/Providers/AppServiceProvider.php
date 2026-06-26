<?php

namespace App\Providers;

use App\Services\OfflineSyncEngine;
use Illuminate\Support\ServiceProvider;

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
        \Illuminate\Support\Facades\Gate::define('admin', function ($user) {
            return $user->role === 'admin';
        });

        if (! $this->app->runningInConsole() && config('database.default') === 'sqlite') {
            $this->app->make(OfflineSyncEngine::class)->initializeLocalDatabase();
        }
    }
}
