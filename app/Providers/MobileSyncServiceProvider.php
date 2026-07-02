<?php

namespace App\Providers;

use App\Domains\Mobile\Services\MobileSyncService;
use App\Domains\Mobile\Services\SQLiteInitializer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class MobileSyncServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Log::channel('mobile-api')->info('=== MOBILE SYNC SERVICE PROVIDER REGISTER START ===');
        $this->app->singleton(MobileSyncService::class, function () {
            Log::channel('mobile-api')->info('Creating MobileSyncService instance');
            return new MobileSyncService;
        });
        Log::channel('mobile-api')->info('=== MOBILE SYNC SERVICE PROVIDER REGISTER COMPLETE ===');
    }

    public function boot(): void
    {
        Log::channel('mobile-api')->info('=== MOBILE SYNC SERVICE PROVIDER BOOT START ===');
        Log::channel('mobile-api')->info('Checking environment...', [
            'app_environment' => app()->environment(),
            'is_nativephp' => app()->environment('nativephp'),
        ]);

        if ($this->app->environment('nativephp')) {
            Log::channel('mobile-api')->info('=== NATIVE PHP ENVIRONMENT DETECTED ===');
            
            // Initialize SQLite database first
            Log::channel('mobile-api')->info('Initializing SQLite database...');
            $initializer = new SQLiteInitializer();
            $initializer->ensureInitialized();

            if ($this->app->runningInConsole()) {
                Log::channel('mobile-api')->info('Running in console, checking for stored token...');
                $token = MobileSyncService::getStoredToken();
                Log::channel('mobile-api')->info('Stored token check complete', ['has_token' => !is_null($token)]);
                
                if ($token) {
                    try {
                        Log::channel('mobile-api')->info('Creating sync service and setting token...');
                        $sync = $this->app->make(MobileSyncService::class);
                        $sync->setToken($token);
                        
                        Log::channel('mobile-api')->info('Checking online status...');
                        if ($sync->isOnline()) {
                            Log::channel('mobile-api')->info('Online, starting sync now...');
                            $sync->syncNow();
                        } else {
                            Log::channel('mobile-api')->info('Offline, skipping auto-sync');
                        }
                    } catch (\Throwable $e) {
                        Log::channel('mobile-api')->error('Mobile auto-sync failed on boot', [
                            'error' => $e->getMessage(),
                            'file' => $e->getFile(),
                            'line' => $e->getLine(),
                            'trace' => $e->getTraceAsString(),
                        ]);
                    }
                }
            }
        }
        Log::channel('mobile-api')->info('=== MOBILE SYNC SERVICE PROVIDER BOOT COMPLETE ===');
    }
}
