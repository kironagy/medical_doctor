<?php

namespace MedicalPlus\AppControl;

use Illuminate\Support\ServiceProvider;

class AppControlServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AppControl::class, fn () => new AppControl);
    }
}
