<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Throwable;
use App\Domains\Media\Models\PatientFile;
use App\Domains\Patients\Models\PatientNote;
use App\Observers\PatientFileObserver;
use App\Observers\PatientNoteObserver;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Singleton ensures the same API token is shared across all repositories in a request
        $this->app->singleton(\App\Services\Mobile\ApiService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register model observers for sync queue enqueueing
        PatientFile::observe(PatientFileObserver::class);
        PatientNote::observe(PatientNoteObserver::class);

        // Only run on NativePHP (mobile) environment
        if (env('NATIVEPHP_RUNNING')) {
            // CRITICAL: Ensure storage directories exist before any service provider
            // tries to use them. The Blade view compiler needs storage/framework/views
            // to cache compiled templates — without it, the app crashes during boot
            // with: "Please provide a valid cache path."
            $this->ensureStorageDirectories();
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

            if ($storedVersion !== $currentVersion) {
                if (config('database.default') === 'sqlite') {
                    $dbPath = config('database.connections.sqlite.database');
                    if ($dbPath && $dbPath !== ':memory:') {
                        if (!File::exists($dbPath)) {
                            File::ensureDirectoryExists(dirname($dbPath));
                            File::put($dbPath, '');
                        }
                    }
                }
                Artisan::call('migrate', ['--force' => true]);
                File::put($versionFile, (string) $currentVersion);
                logger()->info('Mobile database migrated to version ' . $currentVersion);
            }
        } catch (Throwable $e) {
            logger()->error('Mobile migration failed: ' . $e->getMessage(), ['exception' => $e]);
        }
    }

    /**
     * Ensure all required storage directories exist.
     * The Blade view compiler needs storage/framework/views to cache compiled
     * templates. Without it, the app crashes during boot with:
     * "Please provide a valid cache path."
     */
    protected function ensureStorageDirectories(): void
    {
        try {
            $dirs = [
                storage_path('framework/views'),
                storage_path('framework/cache'),
                storage_path('framework/sessions'),
                storage_path('logs'),
            ];

            foreach ($dirs as $dir) {
                if (!File::exists($dir)) {
                    File::ensureDirectoryExists($dir, 0755, true);
                }
            }
        } catch (Throwable $e) {
            logger()->warning('Failed to ensure storage directories: ' . $e->getMessage());
        }
    }

    /**
     * @deprecated Startup sync is now triggered by the frontend after UI renders.
     * This method is retained for backward compatibility but does nothing.
     */
    protected function scheduleStartupSync(): void
    {
        // No-op: sync is now non-blocking, triggered by frontend AJAX after UI renders.
    }
}
