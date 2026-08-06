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
        // Ensure storage directories exist (Android APKs strip empty folders and dotfiles)
        if (config('database.default') === 'sqlite') {
            $paths = [
                storage_path('framework/views'),
                storage_path('framework/cache/data'),
                storage_path('framework/sessions'),
                storage_path('app/uploads/pending'),
            ];
            foreach ($paths as $path) {
                if (!is_dir($path)) {
                    @mkdir($path, 0755, true);
                }
            }
        }

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

        // ── Device: run queued work on the dedicated worker runtime ────────
        // The embedded Android runtime serialises every PHP request through a
        // single global mutex, and QUEUE_CONNECTION=sync makes every dispatched
        // job execute inline inside the caller's request — so a sync or a
        // thumbnail job holds that lock and the whole UI stalls behind it.
        //
        // NativePHP's PHPQueueWorker loops `queue:work --once` on a SEPARATE PHP
        // runtime that explicitly does not contend with UI requests, so pointing
        // the queue at the database driver moves that work off the UI lock and
        // lets the app keep responding while it runs.
        //
        // Scoped to SQLite so the production server (MySQL) keeps its own
        // QUEUE_CONNECTION exactly as configured.
        if (config('database.default') === 'sqlite') {
            config(['queue.default' => 'database']);
        }

        // Only run on NativePHP (mobile) environment
        // Use database driver check (SQLite = embedded, MySQL = production)
        // instead of env('NATIVEPHP_APP_ID') which can be accidentally set
        // on the production server (breaking all mobile auth).
        if (config('database.default') === 'sqlite') {
            config(['app.url' => 'http://127.0.0.1']);
            config(['app.asset_url' => '']);
            \Illuminate\Support\Facades\URL::forceRootUrl('http://127.0.0.1');

            if (!app()->environment('testing')) {
                $this->runMigrationsIfNeeded();
            }
        }
    }

    /**
     * Run database migrations on SQLite (embedded mobile app).
     * Running migrate --force ensures any new tables/columns (e.g. offline_files, sync_status)
     * are created immediately. Laravel's migration runner checks the migrations table and
     * completes in milliseconds if up-to-date.
     */
    protected function runMigrationsIfNeeded(): void
    {
        try {
            Artisan::call('migrate', ['--force' => true]);
        } catch (Throwable $e) {
            logger()->error('Mobile migration failed: ' . $e->getMessage(), ['exception' => $e]);
        }
    }
}
