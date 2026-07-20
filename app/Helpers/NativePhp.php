<?php

namespace App\Helpers;

/**
 * Helper utilities for detecting and working within the NativePHP runtime context.
 */
class NativePhp
{
    /**
     * Check whether the application is currently running inside a NativePHP build.
     *
     * @return bool
     */
    public static function isRunning(): bool
    {
        return (bool) env('NATIVEPHP_RUNNING', false);
    }
}
