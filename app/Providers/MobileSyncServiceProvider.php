<?php

namespace App\Providers;

use App\Domains\Mobile\Services\MobileSyncService;
use Illuminate\Support\ServiceProvider;

class MobileSyncServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MobileSyncService::class, function () {
            return new MobileSyncService;
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole() && $this->app->environment('nativephp')) {
            $token = MobileSyncService::getStoredToken();
            if ($token) {
                try {
                    $sync = $this->app->make(MobileSyncService::class);
                    $sync->setToken($token);
                    if ($sync->isOnline()) {
                        $sync->syncNow();
                    }
                } catch (\Throwable $e) {
                    logger()->error('Mobile auto-sync failed on boot', ['error' => $e->getMessage()]);
                }
            }
        }
    }
}
