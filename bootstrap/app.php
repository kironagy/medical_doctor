<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Define service providers to register
$providers = [
    App\Providers\AppServiceProvider::class,
];

// Only load NativeServiceProvider in NativePHP (mobile) builds
if (env('NATIVEPHP_APP_ID')) {
    $providers[] = App\Providers\NativeServiceProvider::class;
}

return Application::configure(basePath: dirname(__DIR__))
    ->withProviders($providers)
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(\App\Http\Middleware\ParseMobileMultipartMiddleware::class);
        $middleware->statefulApi();
        // ── PERF FIX: Profiler now requires an explicit NATIVEPHP_PROFILER=true ──
        // Previously it ran whenever APP_DEBUG=true (which debug builds ship with),
        // writing REQUEST_START + REQUEST_FINISHED log entries for EVERY request
        // — significant disk I/O on the embedded device and a big part of the
        // perceived slowness. It is investigation-only instrumentation; enable it
        // explicitly when profiling is needed.
        if (env('NATIVEPHP_PROFILER', false)) {
            $middleware->append(\App\Http\Middleware\NativePHPProfilerMiddleware::class);
        }
        $middleware->trustProxies(at: '*');
        // ── CSRF Exemptions ────────────────────────────────────────────
        // /api/session/restore: Bearer-token-authenticated, not a form submission.
        // /api/v1/*: JSON API endpoints called by Vue/axios, not browser forms.
        // /_native/*: Offline routes for the embedded Laravel runtime, which
        //   has no valid CSRF token (no production session). Without this
        //   exemption, all offline POST/PUT/DELETE return HTTP 419.
        // Web form routes (/login, /workspace, /settings, /admin) remain
        // fully CSRF-protected.
        $middleware->validateCsrfTokens(except: [
            '/api/session/restore',
            '/api/v1/*',
            '/_native/*',
            '/chunk/*',
            '/uploads/*',
        ]);
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \App\Http\Middleware\PreventBackHistory::class,
        ]);
        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);
        // [BUG-TRACE] There is no custom Authenticate middleware in this app —
        // the framework's default `auth` middleware handles guest redirects.
        // This hook fires at the exact moment it decides to redirect an
        // unauthenticated request to /login, so we can see WHICH route
        // triggered the redirect to the login page.
        $middleware->redirectGuestsTo(function (\Illuminate\Http\Request $request) {
            \Illuminate\Support\Facades\Log::info('[BUG-TRACE][AuthMiddleware] Guest redirected to login', [
                'path' => $request->path(),
                'method' => $request->method(),
                'database_default' => config('database.default'),
            ]);
            return route('login');
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report(function (\Throwable $e) {
            $sqlState = null;
            $sqliteError = null;
            if ($e instanceof \PDOException) {
                $sqlState = $e->errorInfo[0] ?? $e->getCode();
                $sqliteError = $e->errorInfo[2] ?? $e->getMessage();
            } elseif ($e->getPrevious() instanceof \PDOException) {
                $pdoEx = $e->getPrevious();
                $sqlState = $pdoEx->errorInfo[0] ?? $pdoEx->getCode();
                $sqliteError = $pdoEx->errorInfo[2] ?? $pdoEx->getMessage();
            }

            \Illuminate\Support\Facades\Log::error('LARAVEL EXCEPTION CAUGHT', [
                'exception'    => get_class($e),
                'message'      => $e->getMessage(),
                'sqlstate'     => $sqlState,
                'sqlite_error' => $sqliteError,
                'file'         => $e->getFile(),
                'line'         => $e->getLine(),
                'trace'        => $e->getTraceAsString(),
            ]);
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') ||
                $request->is('_native/*') ||
                $request->is('chunk/*') ||
                $request->is('uploads/*') ||
                $request->is('patients/*') ||
                $request->expectsJson() ||
                $request->wantsJson()
        );

        $exceptions->render(function (\Throwable $e, Request $request) {
            $isUploadOrApi = $request->is('*upload*', '*chunk*', 'patients/*/files', '_native/*', 'api/*') ||
                $request->expectsJson() ||
                $request->wantsJson();

            if ($isUploadOrApi) {
                $sqlState = null;
                $sqliteError = null;
                if ($e instanceof \PDOException) {
                    $sqlState = $e->errorInfo[0] ?? $e->getCode();
                    $sqliteError = $e->errorInfo[2] ?? $e->getMessage();
                } elseif ($e->getPrevious() instanceof \PDOException) {
                    $pdoEx = $e->getPrevious();
                    $sqlState = $pdoEx->errorInfo[0] ?? $pdoEx->getCode();
                    $sqliteError = $pdoEx->errorInfo[2] ?? $pdoEx->getMessage();
                }

                // ── FIX-REL-1: map exception types Laravel would otherwise ──
                // convert to their real statuses AFTER this closure runs (the
                // callback short-circuits Handler::render(), so ValidationException,
                // AuthenticationException etc never reach Laravel's own mapping).
                if ($e instanceof \Illuminate\Validation\ValidationException) {
                    return response()->json([
                        'message' => $e->getMessage(),
                        'errors'  => $e->errors(),
                    ], $e->status);
                }

                if ($e instanceof \Illuminate\Auth\AuthenticationException) {
                    return response()->json(['message' => $e->getMessage() ?: 'Unauthenticated.'], 401);
                }

                if ($e instanceof \Illuminate\Auth\Access\AuthorizationException) {
                    return response()->json(['message' => $e->getMessage() ?: 'This action is unauthorized.'], 403);
                }

                if ($e instanceof \Illuminate\Session\TokenMismatchException) {
                    return response()->json(['message' => 'Your session has expired. Please retry.'], 419);
                }

                if (
                    $e instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException ||
                    $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException
                ) {
                    return response()->json(['message' => 'Not found.'], 404);
                }

                // Preserve the exception's own HTTP status (404, 413, 503, …)
                // instead of forcing 500 on every error. Hardcoding 500 here
                // meant abort(404)/abort(503) calls throughout the _native/*
                // and api/* controllers — including every file-not-found and
                // file-not-cached-yet response — always reached the client as
                // a 500, which is indistinguishable from a real server crash
                // and made this exact class of bug impossible to diagnose
                // from the network tab.
                $status = $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
                    ? $e->getStatusCode()
                    : 500;

                // ── Moved AFTER status mapping: the early returns above (422
                // validation, 401 auth, 403 authz, 419 CSRF, 404 not-found)
                // are all expected client-facing outcomes, not server bugs —
                // logging full request headers + stack trace for every one of
                // them (e.g. a routine 422 on a form) was pure noise. Only
                // exceptions that fall through to here (real 5xx / unmapped
                // errors) get the heavy diagnostic log.
                \Illuminate\Support\Facades\Log::error('UPLOAD/API EXCEPTION RESPONSE', [
                    'url'          => $request->fullUrl(),
                    'method'       => $request->method(),
                    'status'       => $status,
                    'headers'      => $request->headers->all(),
                    'exception'    => get_class($e),
                    'message'      => $e->getMessage(),
                    'sqlstate'     => $sqlState,
                    'sqlite_error' => $sqliteError,
                    'file'         => $e->getFile(),
                    'line'         => $e->getLine(),
                    'trace'        => $e->getTraceAsString(),
                ]);

                $debugFields = app()->hasDebugModeEnabled() ? [
                    'sqlstate'     => $sqlState,
                    'sqlite_error' => $sqliteError,
                    'file'         => $e->getFile(),
                    'line'         => $e->getLine(),
                    'trace'        => $e->getTraceAsString(),
                ] : [];

                return response()->json(array_merge([
                    'error'        => true,
                    'exception'    => get_class($e),
                    'message'      => $e->getMessage(),
                ], $debugFields), $status);
            }

            // ── Embedded/offline app: never show a bare 404/419/500 page ──
            // On the packaged NativePHP build (APP_DEBUG=false) an unhandled
            // web-route exception would otherwise render Laravel's blank
            // production error page inside the WebView, which looks like the
            // app crashed. Bounce back into the SPA instead so it keeps running.
            if (config('database.default') === 'sqlite') {
                if ($e instanceof \Illuminate\Session\TokenMismatchException) {
                    // [BUG-TRACE]
                    \Illuminate\Support\Facades\Log::info('[BUG-TRACE][ExceptionHandler] TokenMismatchException -> retry same URL', [
                        'url' => $request->fullUrl(),
                    ]);
                    // Stale CSRF token from a cached page — just retry the same URL.
                    return redirect($request->fullUrl());
                }

                if (
                    $e instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException ||
                    $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException
                ) {
                    // [BUG-TRACE]
                    \Illuminate\Support\Facades\Log::info('[BUG-TRACE][ExceptionHandler] NotFound/ModelNotFound -> redirect', [
                        'exception' => get_class($e),
                        'url' => $request->fullUrl(),
                        'auth_check' => \Illuminate\Support\Facades\Auth::check(),
                        'redirect_to' => \Illuminate\Support\Facades\Auth::check() ? '/workspace' : '/login',
                    ]);
                    return redirect()->to(\Illuminate\Support\Facades\Auth::check() ? '/workspace' : '/login');
                }

                if (!app()->hasDebugModeEnabled()) {
                    // [BUG-TRACE] This is the broad catch-all — if the login page is
                    // "stuck", this log will show what exception is firing on every
                    // subsequent request and why it keeps landing back on /login.
                    \Illuminate\Support\Facades\Log::info('[BUG-TRACE][ExceptionHandler] Generic catch-all -> redirect', [
                        'exception' => get_class($e),
                        'message' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'url' => $request->fullUrl(),
                        'auth_check' => \Illuminate\Support\Facades\Auth::check(),
                        'redirect_to' => \Illuminate\Support\Facades\Auth::check() ? '/workspace' : '/login',
                    ]);
                    return redirect()->to(\Illuminate\Support\Facades\Auth::check() ? '/workspace' : '/login');
                }
            }
        });
    })
    ->withSchedule(function (\Illuminate\Console\Scheduling\Schedule $schedule): void {
        $schedule->command('uploads:purge-expired --hours=6')->hourly();
        // ═══ SYNC-008 FIX: Removed sync:pending-uploads CRON ═══════════════
        // File uploads are now handled exclusively by SyncEngineService::syncAll()
        // via the POST /_native/api/sync/engine endpoint. The CRON-based
        // command competed with the sync engine, causing race conditions
        // and duplicate uploads.
    })
    ->create();
