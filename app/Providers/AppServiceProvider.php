<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Throwable;
use App\Domains\Media\Models\PatientFile;
use App\Observers\PatientFileObserver;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register model observers
        PatientFile::observe(PatientFileObserver::class);

        // Only run on NativePHP (mobile) environment
        if (env('NATIVEPHP_APP_ID')) {
            $this->runMigrationsIfNeeded();
            $this->scheduleStartupSync();
        }
    }

    /**
     * Run database migrations only when the app version changes.
     * This avoids running migrate on every request in classic mode.
     */
    protected function runMigrationsIfNeeded(): void
    {
        try {
            $versionFile = storage_path('app/.mobile_migration_version');
            $currentVersion = (int) env('NATIVEPHP_APP_VERSION_CODE', 1);
            $storedVersion = 0;

            if (File::exists($versionFile)) {
                $content = File::get($versionFile);
                $storedVersion = (int) trim($content);
            }

            // If stored version differs from current app version, run migrations
            if ($storedVersion !== $currentVersion) {
                Artisan::call('migrate', ['--force' => true]);
                File::put($versionFile, (string) $currentVersion);
                logger()->info('Mobile database migrated to version ' . $currentVersion);
            }
        } catch (Throwable $e) {
            logger()->error('Mobile migration failed: ' . $e->getMessage(), ['exception' => $e]);
        }
    }

    /**
     * Schedule a one-time background sync when the app first opens each session.
     *
     * We defer this to the `booted` event so all services are bound first.
     * The sync runs in a try/catch so it never blocks page load.
     */
    protected function scheduleStartupSync(): void
    {
        $this->app->booted(function () {
            try {
                // Only run once per PHP process (in-memory flag), not once per request.
                // NativePHP spawns a new PHP process for each app launch, so this
                // effectively runs once per app open — exactly what we want.
                static $synced = false;
                if ($synced) {
                    return;
                }
                $synced = true;

                $syncService = $this->app->make(\App\Services\FullSyncService::class);
                $syncService->syncAll();

                logger()->info('[AppServiceProvider] Startup sync completed.');
            } catch (Throwable $e) {
                logger()->warning('[AppServiceProvider] Startup sync failed: ' . $e->getMessage());
            }
        });
    }
}
