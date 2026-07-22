<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // API-first architecture: no local SQLite, no sync services.
        // All data operations go directly to the remote API.
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // No local database observers needed — all data is remote API.
    }
}
