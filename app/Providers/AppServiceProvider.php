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

        // ── Device: run queued work synchronously ───────────────────────────
        // This USED to point the queue at the 'database' driver so
        // PHPQueueWorker (a separate native PHP TSRM context that loops
        // `queue:work --once`) could process jobs off the UI thread's mutex,
        // keeping the app responsive during a sync. That worker has a hard
        // native dependency: native_worker_boot() (php_bridge.c) assumes
        // tsrm_startup() already ran inside the PERSISTENT runtime's
        // php_embed_init(). MainActivity only boots the persistent runtime
        // when bundle_meta.json's runtime_mode isn't "classic" — and this
        // app IS built with runtime_mode=classic, so persistent boot never
        // runs, TSRM is never initialized, and starting the worker crashed
        // the whole app with a native SIGSEGV within ~2s of launch (confirmed
        // via a "Fatal signal 11 ... in tid ... (php-queue-worke)" crash
        // log). Every queued job — including RunManualSyncJob — sat in the
        // `jobs` table forever with attempts=0: files never reached
        // production even though the UI marked them synced locally.
        //
        // The 'sync' driver runs a dispatched job inline in the calling
        // request instead — no worker, no TSRM dependency, no crash risk.
        // The trade-off is the same one production/MySQL already accepts
        // (see routes/web.php's non-SQLite /manual branch, which has always
        // called SyncEngineService::syncAll() inline): the app is
        // unresponsive for the duration of a manual sync. That's a real UX
        // regression versus a working background worker, but a predictable
        // blocking wait beats a silently-stuck queue or a crash.
        if (config('database.default') === 'sqlite') {
            config(['queue.default' => 'sync']);
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
