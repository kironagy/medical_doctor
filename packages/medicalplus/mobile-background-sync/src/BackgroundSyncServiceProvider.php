<?php

namespace MedicalPlus\BackgroundSync;

use Illuminate\Support\ServiceProvider;

class BackgroundSyncServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(BackgroundSync::class, fn () => new BackgroundSync);
    }
}
