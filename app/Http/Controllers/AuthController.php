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
        $initializer = new SQLiteInitializer();
        $initializer->ensureInitialized();

        return Inertia::render('Auth/Login');
    }

    /**
     * Check if we have stored authentication data for NativePHP
     */
    public function checkNativeAuth(Request $request)
    {
        Log::channel('mobile-api')->info('AUTH CONTROLLER: checkNativeAuth CALLED');
        $hasToken = (bool) MobileSyncService::getStoredToken();
        $storedUser = MobileSyncService::getStoredUser();

        return response()->json([
            'hasStoredAuth' => $hasToken && $storedUser,
            'storedUser' => $storedUser,
        ]);
    }

    /**
     * Store NativePHP authentication data (token and user)
     */
    public function storeNativeAuth(Request $request)
    {
        Log::channel('mobile-api')->info('AUTH CONTROLLER: storeNativeAuth CALLED');
        $validated = $request->validate([
            'token' => 'required|string',
            'user' => 'required|array',
            'server_time' => 'nullable|string',
        ]);

        Log::channel('mobile-api')->info('AUTH CONTROLLER: Storing authentication data');

        // Store in cache
        Cache::put('mobile_auth_token', encrypt($validated['token']), now()->addDays(30));
        Cache::put('mobile_auth_user', $validated['user'], now()->addDays(30));
        if ($validated['server_time']) {
            Cache::put('mobile_last_sync_at', $validated['server_time'], now()->addDays(7));
        }

        // Create or update local user
        $localUser = \App\Domains\Users\Models\User::where('uuid', $validated['user']['uuid'] ?? null)
                        ->orWhere('email', $validated['user']['email'])
                        ->first();
        if (!$localUser) {
            Log::channel('mobile-api')->info('AUTH CONTROLLER: Creating local user');
            $localUser = \App\Domains\Users\Models\User::create([
                'uuid' => $validated['user']['uuid'] ?? null,
                'name' => $validated['user']['name'] ?? $validated['user']['email'],
                'email' => $validated['user']['email'],
                'password' => bcrypt(str()->random(32)), // Random password, never used for NativePHP auth
            ]);
        } else {
            $localUser->update([
                'uuid' => $validated['user']['uuid'] ?? $localUser->uuid,
                'name' => $validated['user']['name'] ?? $localUser->name,
            ]);
        }

        // Assign role if available
        if (isset($validated['user']['role'])) {
            try {
                $localUser->assignRole($validated['user']['role']);
            } catch (\Exception $e) {
                Log::channel('mobile-api')->error('AUTH CONTROLLER: Failed to assign role', ['error' => $e->getMessage()]);
            }
        }

        // NO LOCAL WEB LOGIN - NativePHP uses stored token
        Log::channel('mobile-api')->info('AUTH CONTROLLER: Skipping web login (NativePHP mode)');

        // GET /me from production API
        try {
            $api = new \App\Domains\Mobile\Services\ProductionApiService();
            $api->setToken($validated['token']);
            $me = $api->getMe();
            Log::channel('mobile-api')->info('AUTH CONTROLLER: Fetched /me from production', ['me' => $me]);
        } catch (\Exception $e) {
            Log::channel('mobile-api')->error('AUTH CONTROLLER: Failed to fetch /me', ['error' => $e->getMessage()]);
        }

        // Trigger initial sync
        try {
            Log::channel('mobile-api')->info('AUTH CONTROLLER: Starting initial sync');
            $sync = new MobileSyncService();
            $sync->setToken($validated['token']);
            $sync->syncNow();
        } catch (\Exception $e) {
            Log::channel('mobile-api')->error('AUTH CONTROLLER: Initial sync failed', ['error' => $e->getMessage()]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Authentication stored successfully',
        ]);
    }

    /**
     * Handle offline login for NativePHP
     */
    public function offlineNativeLogin(Request $request)
    {
        Log::channel('mobile-api')->info('AUTH CONTROLLER: offlineNativeLogin CALLED');

        $storedToken = MobileSyncService::getStoredToken();
        $storedUser = MobileSyncService::getStoredUser();

        if (!$storedToken || !$storedUser) {
            return response()->json([
                'success' => false,
                'message' => 'No stored authentication data found. Please connect to the internet.'
            ], 401);
        }

        // Verify email matches
        $validated = $request->validate(['email' => 'required|email']);
        if ($storedUser['email'] !== $validated['email']) {
            return response()->json([
                'success' => false,
                'message' => 'Email does not match stored user'
            ], 401);
        }

        // Get or create local user
        $localUser = \App\Domains\Users\Models\User::where('uuid', $storedUser['uuid'] ?? null)
                        ->orWhere('email', $storedUser['email'])
                        ->first();
        if (!$localUser) {
            $localUser = \App\Domains\Users\Models\User::create([
                'uuid' => $storedUser['uuid'] ?? null,
                'name' => $storedUser['name'] ?? $storedUser['email'],
                'email' => $storedUser['email'],
                'password' => bcrypt(str()->random(32)),
            ]);
        }

        // NO LOCAL WEB LOGIN
        Log::channel('mobile-api')->info('AUTH CONTROLLER: Offline login successful (no web auth)');

        return response()->json(['success' => true]);
    }

    public function logout(Request $request)
    {
        Log::channel('mobile-api')->info('AUTH CONTROLLER: logout CALLED');
        Cache::forget('mobile_auth_token');
        Cache::forget('mobile_auth_user');
        Cache::forget('mobile_last_sync_at');

        return redirect('/login');
    }
}
