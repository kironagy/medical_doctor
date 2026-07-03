<?php

namespace App\Providers;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        if (env('NATIVEPHP_APP_ID') && !Schema::hasTable('users')) {
            Artisan::call('migrate', ['--force' => true]);
        }
    }
}
