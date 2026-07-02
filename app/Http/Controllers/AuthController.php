<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use App\Domains\Mobile\Services\MobileSyncService;
use App\Domains\Mobile\Services\SQLiteInitializer;

class AuthController extends Controller
{
    public function showLogin()
    {
        Log::channel('mobile-api')->info('AUTH CONTROLLER: showLogin CALLED');
        // Ensure SQLite is initialized before showing login (for NativePHP)
        if (app()->environment('nativephp')) {
            $initializer = new SQLiteInitializer();
            $initializer->ensureInitialized();
        }

        return Inertia::render('Auth/Login');
    }

    public function login(Request $request)
    {
        Log::channel('mobile-api')->info('AUTH CONTROLLER: login CALLED', ['is_nativephp' => app()->environment('nativephp')]);

        // For NativePHP, use production API login via MobileSyncService
        if (app()->environment('nativephp')) {
            Log::channel('mobile-api')->info('AUTH CONTROLLER: Handling NativePHP login');
            $credentials = $request->validate([
                'email' => ['required', 'email'],
                'password' => ['required'],
            ]);

            $sync = new MobileSyncService();
            $token = $sync->authenticate($credentials['email'], $credentials['password']);

            if (!$token) {
                Log::channel('mobile-api')->error('AUTH CONTROLLER: NativePHP login failed');
                return back()->withErrors([
                    'email' => 'Failed to authenticate with the production server.',
                ])->onlyInput('email');
            }

            // Log the user in locally for the app
            $storedUser = MobileSyncService::getStoredUser();
            $localUser = \App\Domains\Users\Models\User::where('email', $storedUser['email'])->first();
            if (!$localUser) {
                $localUser = \App\Domains\Users\Models\User::create([
                    'name' => $storedUser['name'] ?? $storedUser['email'],
                    'email' => $storedUser['email'],
                    'password' => bcrypt(str()->random(32)), // Random password since we use token auth
                ]);

                // Assign role if available
                if (isset($storedUser['role'])) {
                    try {
                        $localUser->assignRole($storedUser['role']);
                    } catch (\Exception $e) {
                        Log::channel('mobile-api')->error('Failed to assign role', ['error' => $e->getMessage()]);
                    }
                }
            }

            Auth::login($localUser, true);
            $request->session()->regenerate();

            return redirect()->intended('dashboard');
        }

        // Regular web login for web users
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            $request->session()->regenerate();
            return redirect()->intended('dashboard');
        }

        return back()->withErrors([
            'email' => 'The provided credentials do not match our records.',
        ])->onlyInput('email');
    }

    /**
     * Store NativePHP authentication data (token and user)
     */
    public function storeNativeAuth(Request $request)
    {
        $validated = $request->validate([
            'token' => 'required|string',
            'user' => 'required|array',
            'server_time' => 'nullable|string',
        ]);

        // Store in cache
        Cache::put('mobile_auth_token', encrypt($validated['token']), now()->addDays(30));
        Cache::put('mobile_auth_user', $validated['user'], now()->addDays(30));
        if ($validated['server_time']) {
            Cache::put('mobile_last_sync_at', $validated['server_time'], now()->addDays(7));
        }

        // Create or update local user
        $localUser = \App\Domains\Users\Models\User::where('email', $validated['user']['email'])->first();
        if (!$localUser) {
            $localUser = \App\Domains\Users\Models\User::create([
                'name' => $validated['user']['name'] ?? $validated['user']['email'],
                'email' => $validated['user']['email'],
                'password' => bcrypt(str()->random(32)), // Random password since we use token auth
            ]);
        }

        // Assign role if available
        if (isset($validated['user']['role'])) {
            try {
                $localUser->assignRole($validated['user']['role']);
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Failed to assign role', ['error' => $e->getMessage()]);
            }
        }

        // Log the user in locally
        Auth::login($localUser, true);
        $request->session()->regenerate();

        // Trigger initial sync
        try {
            $sync = new MobileSyncService();
            $sync->setToken($validated['token']);
            $sync->syncNow();
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Initial sync failed', ['error' => $e->getMessage()]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Authentication stored successfully',
        ]);
    }

    public function logout(Request $request)
    {
        Log::channel('mobile-api')->info('AUTH CONTROLLER: logout CALLED');
        Auth::logout();
        Cache::forget('mobile_auth_token');
        Cache::forget('mobile_auth_user');
        Cache::forget('mobile_last_sync_at');
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
