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

            // Short 1.5s timeout — enough to detect connectivity without blocking page load
            Http::timeout(2)->head($apiUrl);
            
            // Cache success for 60 seconds
            \Illuminate\Support\Facades\Cache::put($cacheKey, true, 60);
            return self::$isOnline = true;
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
