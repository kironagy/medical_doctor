<?php

namespace MedicalPlus\AppControl;

/**
 * PHP side of the AppControl bridge, same shape as
 * \MedicalPlus\BackgroundSync\BackgroundSync: nativephp_call() only exists
 * inside the native runtime, so this is a silent no-op on the production
 * server.
 */
class AppControl
{
    /**
     * Finish the activity and kill the process. Used after logout — the
     * embedded single-user device auto-logs in whoever it finds in the
     * local SQLite users table the moment /login is hit, so simply clearing
     * the session is not enough to guarantee a clean logged-out state until
     * the process itself restarts.
     */
    public function exit(): void
    {
        if (!function_exists('nativephp_call')) {
            return;
        }

        try {
            nativephp_call('AppControl.Exit', json_encode([]));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
