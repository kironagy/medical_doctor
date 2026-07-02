<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use App\Domains\Mobile\Services\ProductionApiService;
use App\Domains\Mobile\Services\MobileSyncService;
use App\Domains\Mobile\Services\SQLiteInitializer;

class AuthController extends Controller
{
    public function showLogin()
    {
        // Ensure SQLite is initialized before showing login (for NativePHP)
        if (app()->environment('nativephp')) {
            $initializer = new SQLiteInitializer();
            $initializer->ensureInitialized();
        }

        return Inertia::render('Auth/Login');
    }

    public function login(Request $request)
    {
        // Ensure SQLite is initialized before attempting login (for NativePHP)
        if (app()->environment('nativephp')) {
            $initializer = new SQLiteInitializer();
            $initializer->ensureInitialized();
        }

        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        // Check if we're in NativePHP mode
        $isNative = $request->hasHeader('X-NativePHP') || $request->header('User-Agent') === 'NativePHP' || app()->environment('nativephp');

        if ($isNative) {
            // First authenticate with production server
            $api = new ProductionApiService();
            $result = $api->login($credentials['email'], $credentials['password'], 'nativephp-android');

            if (!$result || !isset($result['token'])) {
                return back()->withErrors([
                    'email' => 'Failed to authenticate with production server.',
                ])->onlyInput('email');
            }

            // Store the token and user info
            $token = $result['token'];
            Cache::put('mobile_auth_user', $result['user'], now()->addDays(30));
            Cache::put('mobile_auth_token', encrypt($token), now()->addDays(30));

            // Now do local auth (if user exists locally, or create a dummy user for NativePHP)
            $localUser = \App\Domains\Users\Models\User::where('email', $credentials['email'])->first();
            if (!$localUser) {
                // Create a local dummy user for NativePHP mode
                $localUser = \App\Domains\Users\Models\User::create([
                    'name' => $result['user']['name'] ?? $credentials['email'],
                    'email' => $credentials['email'],
                    'password' => bcrypt($credentials['password']),
                ]);
                // Assign a role if needed
                if (isset($result['user']['role'])) {
                    $localUser->assignRole($result['user']['role']);
                }
            }

            Auth::login($localUser, $request->boolean('remember'));
            $request->session()->regenerate();

            // Trigger initial sync
            try {
                $sync = new MobileSyncService();
                $sync->setToken($token);
                $sync->syncNow();
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Initial sync failed after login', ['error' => $e->getMessage()]);
            }

            return redirect()->intended('dashboard');
        }

        // Regular web login
        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            $request->session()->regenerate();
            return redirect()->intended('dashboard');
        }

        return back()->withErrors([
            'email' => 'The provided credentials do not match our records.',
        ])->onlyInput('email');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        Cache::forget('mobile_auth_token');
        Cache::forget('mobile_auth_user');
        Cache::forget('mobile_last_sync_at');
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
