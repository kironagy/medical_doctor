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
     * During PHPUnit tests, this always returns false to ensure global scopes
     * like DoctorIsolationScope are applied correctly. The check uses PHPUnit
     * constants (PHPUNIT_COMPOSER_INSTALL, __PHPUNIT_PHAR__) because the
     * app()->runningUnitTests() and app()->environment('testing') methods may
     * not reliably return true in this Laravel 13 setup (due to .env overriding
     * phpunit.xml environment variables).
     *
     * @return bool
     */
    public static function isRunning(): bool
    {
        // Never report NativePHP during PHPUnit tests — the environment variable
        // NATIVEPHP_RUNNING=true in .env would otherwise cause DoctorIsolationScope
        // to skip filtering during tests, breaking isolation assertions.
        if (defined('PHPUNIT_COMPOSER_INSTALL') || defined('__PHPUNIT_PHAR__')) {
            return false;
        }

        return (bool) env('NATIVEPHP_RUNNING', false);
    }
}
