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
        // ── Singleton: ApiService ──────────────────────────────────────
        // ApiService manages the production API token. It must be a singleton
        // so that all consumers (SyncEngineService, MakesApiRequests, etc.)
        // read from the SAME in-memory token. Without this, each call to
        // app(ApiService::class) creates a new instance that independently
        // reads session('api_token') in its constructor, leading to:
        //   - Inconsistent token state across code paths
        //   - Race conditions where one path has the token but another doesn't
        $this->app->singleton(\App\Services\Mobile\ApiService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register model observers
        PatientFile::observe(PatientFileObserver::class);

        // Only run on NativePHP (mobile) environment
        // Use database driver check (SQLite = embedded, MySQL = production)
        // instead of env('NATIVEPHP_APP_ID') which can be accidentally set
        // on the production server (breaking all mobile auth).
        if (config('database.default') === 'sqlite') {
            $this->runMigrationsIfNeeded();
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
}
