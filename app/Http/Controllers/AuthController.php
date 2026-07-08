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
                }
            } catch (\Throwable $e) {
                Log::warning('Remote API login failed, sidebar will use local data: ' . $e->getMessage());
            }

            // Role-based redirect: super-admin goes to admin doctors page, others to dashboard
            $user = $request->user();
            $default = $user && $user->role === 'super-admin' ? '/admin/doctors' : '/dashboard';
            return redirect()->intended($default);
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

        return redirect('/login');
    }
}
