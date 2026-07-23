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
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Clean up the remember token
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

        return redirect('/login');
    }
}
