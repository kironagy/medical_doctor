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

            // Obtain production API token for sidebar data sync
            try {
                $tokenResponse = ApiService::loginToRemote($credentials['email'], $credentials['password']);
                if (isset($tokenResponse['token'])) {
                    app(ApiService::class)->setToken($tokenResponse['token']);
                    Log::info('Remote API token acquired successfully');

                    // ── Store encrypted credentials for auto-refresh on 401 ──
                    session(['auth_credentials' => encrypt(json_encode([
                        'email'    => $credentials['email'],
                        'password' => $credentials['password'],
                    ]))]);

                    // ── Flash API token to Inertia page props ──────────────────
                    // Login.vue::onSuccess reads page.props.api_token and stores it
                    // in localStorage as 'np_api_token'. Without this, web-browser
                    // logins never get the Bearer token, and every subsequent API
                    // call (POST /api/v1/mobile/patients, etc.) returns 401/500.
                    session()->flash('api_token', $tokenResponse['token']);
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
            Log::warning('Remote fallback authentication failed: ' . $e->getMessage());
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
