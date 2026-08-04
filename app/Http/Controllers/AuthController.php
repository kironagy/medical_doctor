<?php

namespace App\Http\Controllers;

use App\Services\Mobile\ApiService;
use App\Services\Sync\DownloadSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class AuthController extends Controller
{
    /**
     * Marker file recording that a real login against the production server has
     * completed on this device. Offline auto-login is allowed ONLY when it exists.
     *
     * Written on successful login, deleted on logout. This is what makes the three
     * required behaviours hold simultaneously:
     *   - fresh install  → no marker  → login screen (internet required, by design)
     *   - after logout   → deleted    → login screen (no silent re-login)
     *   - offline restart after a real login → present → straight into the workspace
     */
    public const DEVICE_AUTHENTICATED_MARKER = 'app/.device_authenticated';

    /** Has a real login completed on this device (and not been logged out since)? */
    public static function deviceIsAuthenticated(): bool
    {
        return file_exists(storage_path(self::DEVICE_AUTHENTICATED_MARKER));
    }

    private function markerPath(): string
    {
        return storage_path(self::DEVICE_AUTHENTICATED_MARKER);
    }

    private function markDeviceAuthenticated(): void
    {
        $path = $this->markerPath();
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, '1', LOCK_EX);
    }

    public function showLogin(Request $request)
    {
        Log::info('[Boot] showLogin served via ' . config('database.default') . ' connection');

        if (config('database.default') === 'sqlite' && self::deviceIsAuthenticated()) {
            $user = \App\Domains\Users\Models\User::first();
            if ($user) {
                Log::info('[Boot] Offline-safe auto-login for local user', ['user_id' => $user->id]);
                Auth::login($user);
                return redirect('/workspace');
            }
        }

        if (Auth::check()) {
            return redirect('/dashboard');
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
            $this->markDeviceAuthenticated();

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

                    $this->pullInitialData();
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
            Log::info('Local auth failed. Attempting remote fallback login for: ' . $credentials['email']);
            $tokenResponse = ApiService::loginToRemote($credentials['email'], $credentials['password']);
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

                // Authenticate the user session locally
                Auth::login($localUser, $request->boolean('remember'));
                $request->session()->regenerate();
                $this->markDeviceAuthenticated();

                // Save remote API token and store credentials for the sync engine
                app(ApiService::class)->setToken($tokenResponse['token']);
                session(['auth_credentials' => encrypt(json_encode([
                    'email' => $credentials['email'],
                    'password' => $credentials['password'],
                ]))]);

                Log::info('Successfully authenticated via remote fallback and synced user locally.');

                $this->pullInitialData();

                if ($localUser->role === 'super-admin' || $localUser->hasRole('super-admin')) {
                    return redirect('/admin/doctors');
                }
                return redirect()->intended('/dashboard');
            }
        } catch (\Throwable $e) {
            Log::warning('Remote fallback authentication failed: ' . $e->getMessage());
        }

        return back()->withErrors([
            'email' => 'The provided credentials do not match our records.',
        ])->onlyInput('email');
    }

    /**
     * Pull the doctor's existing patients/notes/visits down from the
     * production server into local SQLite right after login, so the
     * workspace isn't empty before the user ever taps "Sync Now".
     * Best-effort — a failure here must not block login.
     */
    private function pullInitialData(): void
    {
        if (config('database.default') !== 'sqlite') {
            return;
        }

        try {
            $summary = app(DownloadSyncService::class)->downloadChanges();
            Log::info('[Boot] Initial data pull after login', $summary);
        } catch (\Throwable $e) {
            Log::warning('[Boot] Initial data pull after login failed: ' . $e->getMessage());
        }
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
        // Revoke offline auto-login so the next launch shows the login screen.
        @unlink($this->markerPath());
        Log::info('[Auth] Logout complete — token cleared, offline auto-login revoked');

        return redirect('/login');
    }
}
