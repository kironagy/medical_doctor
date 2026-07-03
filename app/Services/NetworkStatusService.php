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

        // Check if NATIVEPHP_APP_ID is not set, meaning we're on the main web app
        // The main web app is always considered "online"
        if (!env('NATIVEPHP_APP_ID')) {
            return self::$isOnline = true;
        }

        try {
            // Ping the mobile API to check connectivity
            // We use a small timeout to avoid long hangs when offline
            $apiUrl = env('MOBILE_API_URL');
            if (!$apiUrl) {
                return self::$isOnline = false;
            }

            // We can just ping the domain, or a specific fast endpoint if available.
            // Using a simple GET to the base URL or /api/v1/mobile/ping if it existed.
            // We will just do a HEAD request to the API root.
            Http::timeout(3)->head($apiUrl);
            
            return self::$isOnline = true;
        } catch (ConnectionException $e) {
            return self::$isOnline = false;
        } catch (\Exception $e) {
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
