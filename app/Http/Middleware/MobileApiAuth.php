<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * ───────────────────────────────────────────────────────────────────────────
 *  MobileApiAuth — Bearer token fallback for mobile API endpoints
 * ───────────────────────────────────────────────────────────────────────────
 *
 * Problem:
 *   The SyncEngine (embedded Laravel) sends requests to the production API
 *   using a Bearer token via GuzzleHttp. Sanctum's `auth:sanctum` middleware
 *   rejects these tokens intermittently, causing 401 on every mobile endpoint.
 *
 * Solution:
 *   This middleware is stacked BEFORE `auth:sanctum` on the mobile route group.
 *   It tries manual Bearer token resolution via PersonalAccessToken::findToken()
 *   + Auth::guard('sanctum')->setUser(). By setting the user directly on the
 *   sanctum guard, the subsequent `auth:sanctum` middleware (SanctumGuard's
 *   user() method) returns immediately without trying its own Bearer token
 *   resolution (which has been failing intermittently for SyncEngine requests).
 *
 *   For requests from the production frontend (SPA) that use session cookies
 *   instead of Bearer tokens, this middleware does nothing and passes through
 *   to `auth:sanctum` which handles session-based auth normally.
 *
 *   If BOTH this middleware and `auth:sanctum` fail, the request gets 401.
 * ───────────────────────────────────────────────────────────────────────────
 */
class MobileApiAuth
{
    public function handle(Request $request, Closure $next)
    {
        // ── Only handle Bearer token requests ─────────────────────────────
        // SPA requests (session cookies, no Bearer token) pass through to
        // `auth:sanctum` which handles session-based auth normally.
        $bearerToken = $request->bearerToken();

        if (!$bearerToken) {
            // Not a Bearer token request — pass through to auth:sanctum
            return $next($request);
        }

        // ── Try manual Bearer token resolution ───────────────────────────
        // The SyncEngine sends Bearer tokens via GuzzleHttp. Sanctum's
        // auth:sanctum middleware can't resolve these tokens reliably (the
        // root problem we're working around). We do it manually here.
        try {
            /** @var \Laravel\Sanctum\PersonalAccessToken|null $accessToken */
            $accessToken = \Laravel\Sanctum\PersonalAccessToken::findToken($bearerToken);

            if ($accessToken && $accessToken->tokenable) {
                // Set the user directly on the sanctum guard so that when
                // `auth:sanctum` middleware runs next, SanctumGuard's
                // user() method returns immediately (it checks $this->user
                // first) without trying its own Bearer token resolution.
                Auth::guard('sanctum')->setUser($accessToken->tokenable);

                Log::info('[MobileApiAuth] Authenticated via Bearer token', [
                    'user_id' => $accessToken->tokenable->id,
                    'path' => $request->path(),
                ]);

                return $next($request);
            }

            // Token present but invalid — return 401
            Log::warning('[MobileApiAuth] Invalid Bearer token', [
                'token_prefix' => substr($bearerToken, 0, 20) . '...',
                'path' => $request->path(),
            ]);

            return response()->json(['message' => 'Unauthenticated.'], 401);
        } catch (\Throwable $e) {
            Log::warning('[MobileApiAuth] Bearer token resolution failed', [
                'error' => $e->getMessage(),
                'path' => $request->path(),
            ]);

            return response()->json(['message' => 'Unauthenticated.'], 401);
        }
    }
}
