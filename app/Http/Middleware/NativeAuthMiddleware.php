<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Domains\Mobile\Services\MobileSyncService;

class NativeAuthMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $logger = Log::channel('mobile-api');
        $logger->info('=== NATIVE AUTH MIDDLEWARE START ===');

        // For NativePHP app, we rely entirely on the locally stored Sanctum token
        // to determine if the user is authenticated. No session cookies are used.
        $storedToken = MobileSyncService::getStoredToken();
        $storedUser = MobileSyncService::getStoredUser();

        $logger->info('Stored auth data check', [
            'has_token' => !is_null($storedToken),
            'has_user' => !is_null($storedUser),
            'user_email' => $storedUser['email'] ?? null,
        ]);

        if (!$storedToken || !$storedUser) {
            $logger->warning('Missing stored auth data, redirecting to login');
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
            return redirect('/login');
        }

        $logger->info('=== NATIVE AUTH MIDDLEWARE PASSED ===');
        return $next($request);
    }
}
