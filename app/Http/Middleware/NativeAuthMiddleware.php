<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Domains\Mobile\Services\MobileSyncService;

class NativeAuthMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        // For NativePHP app, we rely entirely on the locally stored Sanctum token
        // to determine if the user is authenticated. No session cookies are used.
        $storedToken = MobileSyncService::getStoredToken();
        $storedUser = MobileSyncService::getStoredUser();

        if (!$storedToken || !$storedUser) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
            return redirect('/login');
        }

        return $next($request);
    }
}
