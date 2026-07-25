<?php

namespace App\Http\Controllers;

use App\Services\Mobile\ApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class AuthController extends Controller
{
    public function showLogin(Request $request)
    {
        if (Auth::check()) {
            return redirect('/');
        }
        return Inertia::render('Auth/Login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            $request->session()->regenerate();

            // Generate a Sanctum "remember" token for session restore on app restart.
            // This token is passed to the frontend via Inertia shared props and
            // stored in localStorage. On app restart, if the WebView lost the
            // session cookie, the token can restore the web session.
            try {
                $rememberToken = $request->user()->createToken('session-remember')->plainTextToken;
                session(['session_remember_token' => encrypt($rememberToken)]);
            } catch (\Throwable $e) {
                Log::warning('Failed to generate session-remember token: ' . $e->getMessage());
            }

            // Obtain production API token for sidebar data sync
            try {
                $tokenResponse = ApiService::loginToRemote($credentials['email'], $credentials['password']);
                if (isset($tokenResponse['token'])) {
                    app(ApiService::class)->setToken($tokenResponse['token']);
                    Log::info('Remote API token acquired successfully');

                    // ── Store encrypted credentials for auto-refresh on 401 ──
                    // These are stored encrypted in the local SQLite session so the
                    // sync engine can automatically re-login when the token expires.
                    // The credentials are only decryptable with this device's APP_KEY.
                    session(['auth_credentials' => encrypt(json_encode([
                        'email' => $credentials['email'],
                        'password' => $credentials['password'],
                    ]))]);
                }
            } catch (\Throwable $e) {
                Log::warning('Remote API login failed, sidebar will use local data: ' . $e->getMessage());
            }

            // Role-based redirect: super-admin goes to admin doctors page, others to dashboard
            $user = $request->user();
            if ($user && ($user->role === 'super-admin' || $user->hasRole('super-admin'))) {
                return redirect('/admin/doctors');
            }

            return redirect()->intended('/dashboard');
        }

        return back()->withErrors([
            'email' => 'The provided credentials do not match our records.',
        ])->onlyInput('email');
    }

    public function logout(Request $request)
    {
        // ── TOKEN-003 FIX: Clean up ALL tokens, not just the remember token ──
        // Previous code only cleaned up the session-remember Sanctum token.
        // It left behind:
        //   1. session('api_token') — production API token
        //   2. session('auth_credentials') — encrypted email/password
        //   3. The disk token file (storage/app/.api_sync_token)
        //   4. localStorage tokens (handled by frontend JS)
        //
        // Now we clean up everything to prevent stale credentials from
        // persisting after logout. This ensures that re-login doesn't
        // accidentally reuse old credentials.

        // 1. Delete the Sanctum session-remember token
        try {
            $encrypted = session('session_remember_token');
            if ($encrypted) {
                $token = decrypt($encrypted);
                \Laravel\Sanctum\PersonalAccessToken::findToken($token)?->delete();
            }
        } catch (\Throwable $e) {
            // Silently clean up
        }
        session()->forget('session_remember_token');

        // 2. Clean up the production API token via ApiService singleton
        try {
            $apiService = app(\App\Services\Mobile\ApiService::class);
            $apiService->setToken(null); // This clears session + disk file
        } catch (\Throwable $e) {
            Log::warning('Failed to clean up API token on logout: ' . $e->getMessage());
        }

        // 3. Clean up stored credentials
        session()->forget('auth_credentials');

        // 4. Clean up any other authentication artifacts
        session()->forget('api_token');

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
