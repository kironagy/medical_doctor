<?php

namespace MedicalPlus\BackgroundSync;

/**
 * PHP side of the BackgroundSync bridge. Mirrors the pattern used by
 * \Native\Mobile\Dialog: nativephp_call() is a function registered by the
 * native runtime on-device (a no-op stub outside it), so every call here is
 * safe to make unconditionally — on production (MySQL, no native runtime)
 * function_exists() is false and every method is a silent no-op.
 */
class BackgroundSync
{
    /**
     * Start the foreground service and show its ongoing notification.
     * Safe to call even if a service is already running — Android's Service
     * lifecycle coalesces repeated start calls onto the same instance.
     */
    public function start(string $title, string $message = ''): void
    {
        $this->call('BackgroundSync.Start', [
            'title' => $title,
            'message' => $message,
        ]);
    }

    /**
     * Update the ongoing notification. $percent of null shows an
     * indeterminate progress bar; 0-100 shows a determinate one.
     */
    public function progress(string $message, ?int $percent = null): void
    {
        $this->call('BackgroundSync.Progress', array_filter([
            'message' => $message,
            'percent' => $percent,
        ], fn ($v) => $v !== null));
    }

    /**
     * Stop the foreground service. Shows a one-shot completion notification
     * with $message before the ongoing notification is dismissed.
     */
    public function stop(?string $message = null): void
    {
        $this->call('BackgroundSync.Stop', array_filter([
            'message' => $message,
        ], fn ($v) => $v !== null));
    }

    private function call(string $method, array $payload): void
    {
        if (!function_exists('nativephp_call')) {
            return;
        }

        try {
            nativephp_call($method, json_encode($payload));
        } catch (\Throwable $e) {
            // Never let a notification failure break the sync/upload it is
            // reporting on.
            report($e);
        }
    }
}
