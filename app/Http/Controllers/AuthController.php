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
        // [BUG-TRACE] Entry point + the exact state before any decision is made.
        Log::info('[BUG-TRACE][AuthController::showLogin] ENTRY', [
            'database_default' => config('database.default'),
            'user_count' => \App\Domains\Users\Models\User::count(),
            'auth_check_before' => Auth::check(),
        ]);

        if (config('database.default') === 'sqlite') {
            $user = \App\Domains\Users\Models\User::first();
            // [BUG-TRACE]
            Log::info('[BUG-TRACE][AuthController::showLogin] sqlite branch', [
                'user_found' => (bool) $user,
                'user_id' => $user?->id,
            ]);
            if ($user) {
                // [BUG-TRACE]
                Log::info('[BUG-TRACE][AuthController::showLogin] before Auth::login()');
                Auth::login($user);
                // [BUG-TRACE]
                Log::info('[BUG-TRACE][AuthController::showLogin] after Auth::login()', [
                    'auth_check_after' => Auth::check(),
                ]);
                return redirect('/workspace');
            }
        }

        if (Auth::check()) {
            // [BUG-TRACE]
            Log::info('[BUG-TRACE][AuthController::showLogin] already authenticated -> redirect /dashboard');
            return redirect('/dashboard');
        }

        // [BUG-TRACE] Reaching this line means the Login page WILL be rendered.
        Log::info('[BUG-TRACE][AuthController::showLogin] FALLTHROUGH -> rendering Auth/Login page', [
            'database_default' => config('database.default'),
            'user_count' => \App\Domains\Users\Models\User::count(),
            'auth_check' => Auth::check(),
        ]);
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

            // Obtain API token for mobile API requests and sync
            try {
                $user = $request->user();
                $token = null;

                if (config('database.default') === 'sqlite') {
                    // On mobile (SQLite), we must obtain a token from the remote production server
                    $tokenResponse = ApiService::loginToRemote($credentials['email'], $credentials['password']);
                    $token = $tokenResponse['token'] ?? null;
                } else {
                    // On production (MySQL), generate local Sanctum token directly to avoid HTTP loopback loops
                    $token = $user->createToken('auth_token')->plainTextToken;
                }

                if ($token) {
                    app(ApiService::class)->setToken($token);
                    Log::info('API token acquired successfully');

                    // ── Store encrypted credentials for auto-refresh on 401 ──
                    session(['auth_credentials' => encrypt(json_encode([
                        'email'    => $credentials['email'],
                        'password' => $credentials['password'],
                    ]))]);
                }
            } catch (\Throwable $e) {
                Log::warning('API token acquisition failed: ' . $e->getMessage());
            }

            // Role-based redirect: super-admin goes to admin doctors page, others to dashboard
            $user = $request->user();
            if ($user && ($user->role === 'super-admin' || $user->hasRole('super-admin'))) {
                return redirect('/admin/doctors');
            }

            return redirect()->intended('/dashboard');
        }


        // If local authentication fails, attempt authentication against remote production API
        // (essential for clean installs where the local SQLite database contains 0 users)
        try {
            Log::error('[LOGIN-DIAG] Local auth failed, attempting remote fallback', ['email' => $credentials['email']]);
            $tokenResponse = ApiService::loginToRemote($credentials['email'], $credentials['password']);
            Log::error('[LOGIN-DIAG] loginToRemote result', [
                'has_token' => isset($tokenResponse['token']),
                'has_user'  => isset($tokenResponse['user']),
                'keys'      => is_array($tokenResponse) ? array_keys($tokenResponse) : gettype($tokenResponse),
            ]);
            if (isset($tokenResponse['token']) && isset($tokenResponse['user'])) {
                $remoteUser = $tokenResponse['user'];

                // Create or update the user record locally in SQLite
                \App\Domains\Users\Models\User::unguard();
                $localUser = \App\Domains\Users\Models\User::updateOrCreate(
                    ['email' => $remoteUser['email']],
                    [
                        'name' => $remoteUser['name'],
                        'password' => bcrypt($credentials['password']), // Save local hashed password for future offline logins
                        'role' => $remoteUser['role'] ?? 'doctor',
                        'phone' => $remoteUser['phone'] ?? null,
                        'address' => $remoteUser['address'] ?? null,
                        'specialization' => $remoteUser['specialization'] ?? null,
                        'code' => $remoteUser['code'] ?? null,
                        'status' => $remoteUser['status'] ?? 'active',
                        'uuid' => $remoteUser['uuid'] ?? (string) \Illuminate\Support\Str::uuid(),
                    ]
                );
                \App\Domains\Users\Models\User::reguard();
                Log::error('[LOGIN-DIAG] local user upserted', [
                    'id' => $localUser->id,
                    'exists_in_db' => \App\Domains\Users\Models\User::where('id', $localUser->id)->exists(),
                    'total_users_now' => \App\Domains\Users\Models\User::count(),
                ]);

                // Authenticate the user session locally
                Auth::login($localUser, $request->boolean('remember'));
                $request->session()->regenerate();

                // Save remote API token and store credentials for the sync engine
                app(ApiService::class)->setToken($tokenResponse['token']);
                session(['auth_credentials' => encrypt(json_encode([
                    'email' => $credentials['email'],
                    'password' => $credentials['password'],
                ]))]);

                Log::info('Successfully authenticated via remote fallback and synced user locally.');

                if ($localUser->role === 'super-admin' || $localUser->hasRole('super-admin')) {
                    return redirect('/admin/doctors');
                }
                return redirect()->intended('/dashboard');
            }
        } catch (\Throwable $e) {
            // Log::error (not warning): device LOG_LEVEL=error filters warning
            // out entirely. This is the only place that explains why a device
            // stays on 0 local users despite the doctor reporting a working
            // login — something inside the remote-fallback block (the API
            // login call itself, or the local User::updateOrCreate() upsert)
            // is throwing and getting swallowed here.
            Log::error('Remote fallback authentication failed', [
                'email'     => $credentials['email'] ?? null,
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ]);
        }

        return back()->withErrors([
            'email' => 'The provided credentials do not match our records.',
        ])->onlyInput('email');
    }

    public function logout(Request $request)
    {
        // ── Clean up the production API token via ApiService singleton ──
        // This clears the session-stored token and the disk file.
        try {
            $apiService = app(\App\Services\Mobile\ApiService::class);
            $apiService->setToken(null);
        } catch (\Throwable $e) {
            Log::warning('Failed to clean up API token on logout: ' . $e->getMessage());
        }

        // Clean up stored credentials
        session()->forget('auth_credentials');
        session()->forget('api_token');

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
