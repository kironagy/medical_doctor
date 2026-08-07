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
                storage_path('logs'),
            ];
            foreach ($paths as $path) {
                if (!is_dir($path)) {
                    @mkdir($path, 0755, true);
                }
            }
        }

        // ── Singleton: RemoteApiService ──────────────────────────────────
        // RemoteApiService — not ApiService — is where the production Bearer
        // token actually lives ($this->token, set via setToken()). It MUST be
        // the singleton, not just ApiService: ApiService::class was bound
        // singleton below it (kept for BC), but ApiService merely WRAPS a
        // RemoteApiService instance it auto-resolves once at construction.
        // FileSyncService — the class that actually performs chunk uploads —
        // type-hints RemoteApiService directly in its own constructor. Since
        // RemoteApiService itself was never bound as singleton, Laravel's
        // container handed FileSyncService a SEPARATE, freshly-constructed
        // instance from the one living inside the ApiService singleton — two
        // different objects, each independently loading its own token from
        // session/file at construction time. When
        // routes/web.php's POST /manual set the token via
        // app(ApiService::class)->setToken(...), that write landed on the
        // ApiService-owned instance; FileSyncService's instance had no
        // guaranteed way to see it. This is exactly the class of bug the
        // ApiService singleton comment below already describes and was
        // trying to prevent — it just didn't reach the one class (RemoteApiService)
        // where the token is actually stored and where the real HTTP calls are made.
        // Net effect: SyncEngineService::syncAll()'s own auth guard (via the
        // ApiService-owned instance) could see a valid token and proceed,
        // while FileSyncService's chunk/init calls — using a different,
        // possibly tokenless instance — silently never left the device.
        // Binding RemoteApiService itself as the singleton makes every
        // consumer share the one true token state, request-wide.
        $this->app->singleton(\App\Services\Mobile\RemoteApiService::class);

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
