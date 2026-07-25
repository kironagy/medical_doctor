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
