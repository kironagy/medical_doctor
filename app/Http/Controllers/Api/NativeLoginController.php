<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Domains\Mobile\Services\ProductionApiService;
use App\Domains\Mobile\Services\MobileSyncService;
use App\Domains\Users\Models\User;

class NativeLoginController extends Controller
{
    public function login(Request $request)
    {
        Log::channel('mobile-api')->info('LOGIN REQUEST RECEIVED');

        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $api = new ProductionApiService();

        Log::channel('mobile-api')->info('CALLING PRODUCTION API');
        $loginResponse = $api->login($validated['email'], $validated['password'], 'nativephp-android');

        if (!$loginResponse || !isset($loginResponse['token'])) {
            Log::channel('mobile-api')->error('PRODUCTION RESPONSE: FAILED');
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials or failed to connect to production server.'
            ], 401);
        }

        Log::channel('mobile-api')->info('PRODUCTION RESPONSE: SUCCESS');

        $token = $loginResponse['token'];
        $user = $loginResponse['user'];
        $serverTime = $loginResponse['server_time'] ?? null;

        // Save Sanctum token
        Cache::put('mobile_auth_token', encrypt($token), now()->addDays(30));
        Log::channel('mobile-api')->info('TOKEN STORED');

        // Save authenticated user
        Cache::put('mobile_auth_user', $user, now()->addDays(30));
        if ($serverTime) {
            Cache::put('mobile_last_sync_at', $serverTime, now()->addDays(7));
        }

        $localUser = User::where('uuid', $user['uuid'] ?? null)
            ->orWhere('email', $user['email'])
            ->first();

        if (!$localUser) {
            $localUser = User::create([
                'uuid' => $user['uuid'] ?? null,
                'name' => $user['name'] ?? $user['email'],
                'email' => $user['email'],
                'password' => bcrypt(str()->random(32)),
            ]);
        } else {
            $localUser->update([
                'uuid' => $user['uuid'] ?? $localUser->uuid,
                'name' => $user['name'] ?? $localUser->name,
            ]);
        }

        if (isset($user['role'])) {
            try {
                $localUser->assignRole($user['role']);
            } catch (\Exception $e) {
                // Ignore role errors
            }
        }

        Log::channel('mobile-api')->info('USER STORED');

        // Call auth/me
        $api->setToken($token);
        $me = $api->getMe();
        if ($me) {
            Log::channel('mobile-api')->info('AUTH/ME COMPLETED');
            // update user cache with latest from /me if needed
            Cache::put('mobile_auth_user', $me, now()->addDays(30));
        }

        // Call initial sync
        Log::channel('mobile-api')->info('STARTING INITIAL SYNC');
        try {
            $sync = new MobileSyncService();
            $sync->setToken($token);
            $sync->syncNow();
            Log::channel('mobile-api')->info('SYNC COMPLETE');
        } catch (\Exception $e) {
            Log::channel('mobile-api')->error('SYNC FAILED', ['error' => $e->getMessage()]);
        }

        Log::channel('mobile-api')->info('WORKSPACE READY');

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'user' => $user,
        ]);
    }

    public function offlineLogin(Request $request)
    {
        Log::channel('mobile-api')->info('OFFLINE LOGIN REQUEST RECEIVED');

        $validated = $request->validate([
            'email' => 'required|email',
        ]);

        $storedToken = MobileSyncService::getStoredToken();
        $storedUser = MobileSyncService::getStoredUser();

        if (!$storedToken || !$storedUser) {
            return response()->json([
                'success' => false,
                'message' => 'Internet connection is required for the first login.'
            ], 401);
        }

        if ($storedUser['email'] !== $validated['email']) {
            return response()->json([
                'success' => false,
                'message' => 'Internet connection is required for the first login.'
            ], 401);
        }

        Log::channel('mobile-api')->info('OFFLINE LOGIN SUCCESSFUL');
        Log::channel('mobile-api')->info('WORKSPACE READY');

        return response()->json(['success' => true]);
    }
}
