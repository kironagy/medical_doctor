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
        if (env('APP_DEBUG', false)) {
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
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })
    ->withSchedule(function (\Illuminate\Console\Scheduling\Schedule $schedule): void {
        $schedule->command('uploads:purge-expired --hours=6')->hourly();
        $schedule->command('sync:pending-uploads --batch=5')->everyFiveMinutes();
    })
    ->create();
