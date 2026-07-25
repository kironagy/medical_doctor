<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * ───────────────────────────────────────────────────────────────────────────
 *  AuthenticateWithBearer — Shared Bearer Token Resolution Helper
 * ───────────────────────────────────────────────────────────────────────────
 *
 * PURPOSE:
 *   Eliminate the duplicated Bearer token resolution pattern that was
 *   copied into 7+ controllers. This trait provides a single method that
 *   all controllers can use to resolve a user from a Bearer token.
 *
 * USAGE:
 *   use AuthenticateWithBearer;
 *
 *   $user = $this->resolveFromBearerToken($request);
 *   if ($user) {
 *       // User authenticated via Bearer token
 *   }
 *
 * HOW IT WORKS:
 *   1. Extracts the Bearer token from the Authorization header.
 *   2. Resolves it via Sanctum's PersonalAccessToken::findToken().
 *   3. If valid and tokenable exists, logs the user in and returns the user.
 *   4. If invalid, returns null (does NOT throw — caller decides how to handle).
 *   5. If exception occurs during resolution, logs and returns null.
 *
 * This replaces the following duplicated implementations:
 *   - WorkspaceController::storePatient()
 *   - Api\NoteController::store()
 *   - Api\CategoryController::*()
 *   - Api\OfflineNoteController::store()
 *   - Api\Mobile\PatientController::*()
 *   - Api\Mobile\NoteController::store()
 *   - MobileApiAuth middleware
 * ───────────────────────────────────────────────────────────────────────────
 */
trait AuthenticateWithBearer
{
    /**
     * Resolve a user from the request's Bearer token (if present).
     *
     * NOTE: This method does NOT log the user into the session (no side effects).
     * It only resolves and returns the user. Callers that need session state
     * (e.g., WorkspaceController, NoteController) must call Auth::login()
     * themselves after calling this method.
     *
     * @param  Request  $request
     * @return \App\Domains\Users\Models\User|null
     */
    private function resolveFromBearerToken(Request $request): ?\App\Domains\Users\Models\User
    {
        $bearerToken = $request->bearerToken();

        if (!$bearerToken) {
            return null;
        }

        try {
            /** @var \Laravel\Sanctum\PersonalAccessToken|null $accessToken */
            $accessToken = \Laravel\Sanctum\PersonalAccessToken::findToken($bearerToken);

            if ($accessToken && $accessToken->tokenable) {
                /** @var \App\Domains\Users\Models\User $user */
                $user = $accessToken->tokenable;

                Log::info('[AuthenticateWithBearer] Resolved user from Bearer token', [
                    'user_id' => $user->id,
                    'path' => $request->path(),
                ]);

                return $user;
            }

            Log::warning('[AuthenticateWithBearer] Invalid Bearer token', [
                'token_prefix' => substr($bearerToken, 0, 20) . '...',
                'path' => $request->path(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[AuthenticateWithBearer] Bearer token resolution failed', [
                'error' => $e->getMessage(),
                'path' => $request->path(),
            ]);
        }

        return null;
    }
}
