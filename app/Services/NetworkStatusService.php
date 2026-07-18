<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Client\ConnectionException;

class NetworkStatusService
{
    private static ?bool $isOnline = null;

    /**
     * Determine if the device is currently online and can reach the API.
     */
    public static function isOnline(): bool
    {
        if (self::$isOnline !== null) {
            return self::$isOnline;
        }

        // Use a cache key so within the same request cycle we don't ping twice
        $cacheKey = 'network_online_status';
        $cached = \Illuminate\Support\Facades\Cache::get($cacheKey);
        if ($cached !== null) {
            return self::$isOnline = $cached;
        }

    try {
        $apiUrl = config('app.mobile_api_url', 'https://prof-hosam-fekry.online/api/v1/mobile');
        if (!$apiUrl) {
            return self::$isOnline = false;
        }

        // Check the API base URL directly instead of a specific endpoint.
        // The /api/v1/mobile prefix is a route group — Laravel returns 404
        // for GET /api/v1/mobile because no GET route matches the prefix itself,
        // but any response (including 404) proves the server is reachable.
        // We specifically exclude 5xx which indicates a server-side failure.
        error_log('[NETWORK_DEBUG] Checking connectivity to ' . $apiUrl);
        $response = Http::timeout(3)->get($apiUrl);
        $online = $response->status() < 500;

        error_log('[NETWORK_DEBUG] Connectivity check result: ' . ($online ? 'ONLINE' : 'OFFLINE') . ' (status: ' . $response->status() . ')');

        // Cache success for 60 seconds
        \Illuminate\Support\Facades\Cache::put($cacheKey, $online, $online ? 60 : 15);
        return self::$isOnline = $online;
    } catch (ConnectionException $e) {
            // Cache failure for 15 seconds only so we re-check quickly when connection returns
            \Illuminate\Support\Facades\Cache::put($cacheKey, false, 15);
            return self::$isOnline = false;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Cache::put($cacheKey, false, 15);
            return self::$isOnline = false;
        }
    }

    /**
     * Force the service to re-check the network status on the next call.
     */
    public static function clearCache(): void
    {
        self::$isOnline = null;
    }

    /**
     * Temporarily set the online status for testing or during a request cycle.
     */
    public static function setOnline(bool $status): void
    {
        self::$isOnline = $status;
    }
}
