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
