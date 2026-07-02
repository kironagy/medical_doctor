<?php

namespace App\Domains\Mobile\Auth;

use App\Domains\Users\Models\User;
use App\Domains\Mobile\Services\MobileSyncService;

class NativeAuth
{
    /**
     * Get the currently authenticated local user based on stored token.
     */
    public static function user(): ?User
    {
        $storedUser = MobileSyncService::getStoredUser();
        if (!$storedUser) {
            return null;
        }

        // Return the local SQLite user
        return User::where('uuid', $storedUser['uuid'] ?? null)
            ->orWhere('email', $storedUser['email'])
            ->first();
    }

    /**
     * Get the currently authenticated local user's ID.
     */
    public static function id(): ?int
    {
        return self::user()?->id;
    }

    /**
     * Check if user is authenticated locally.
     */
    public static function check(): bool
    {
        return !is_null(self::user());
    }
}
