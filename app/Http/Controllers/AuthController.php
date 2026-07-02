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

    public function logout(Request $request)
    {
        Log::channel('mobile-api')->info('AUTH CONTROLLER: logout CALLED');
        Cache::forget('mobile_auth_token');
        Cache::forget('mobile_auth_user');
        Cache::forget('mobile_last_sync_at');

        return redirect('/login');
    }
}
