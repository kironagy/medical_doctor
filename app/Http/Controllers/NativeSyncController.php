<?php

namespace App\Http\Controllers;

use App\Services\FullSyncService;
use App\Services\SyncQueueService;
use App\Services\Sync\SyncManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class NativeSyncController extends Controller
{
        public function __construct(
        private FullSyncService $fullSync,
        private SyncQueueService $syncQueue,
    ) {}

    /**
     * POST /api/native/sync/background
     *
     * Lightweight background sync endpoint called by the frontend
     * when connectivity is restored or on periodic timer.
     * Runs a quick push of pending operations + incremental pull.
     * This endpoint is designed to be called frequently without
     * blocking the UI or causing performance issues.
     */
    public function backgroundSync(Request $request)
    {
        Log::info('[NativeSyncController] Background sync requested.');

        try {
            $backgroundSync = app(\App\Services\BackgroundSyncService::class);
            $backgroundSync->run();
            return response()->json(['success' => true]);
        } catch (\Throwable $e) {
            Log::warning('[NativeSyncController] Background sync error: ' . $e->getMessage());
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

/**
 * Sync all pending operations with the remote, then pull fresh remote data
 * into local SQLite.
 *
 * IMPORTANT: This endpoint is called from the frontend (syncAndRefresh) AFTER
 * the UI has already rendered. It runs in the background and should NEVER
 * block the response — the frontend handles failures gracefully.
 */
public function sync(Request $request)
{
    Log::info('NativeSyncController: Starting Sync');

    // Verify API token is available for sync operations
    $token = $this->getToken();
    if (!$token) {
        Log::warning('[NativeSyncController] Sync attempted without API token');
        return response()->json([
            'error' => 'No API token available. Please login again.',
            'auth_error' => true,
        ], 401);
    }

    Log::info('[NativeSyncController] Token available: YES (len=' . strlen($token) . ')');

    // Log local patient count before sync
    try {
        $beforeCount = \App\Domains\Patients\Models\Patient::count();
        Log::info('[NativeSyncController] Local patients BEFORE sync: ' . $beforeCount);
    } catch (\Throwable $e) {
        Log::warning('[NativeSyncController] Count before sync failed: ' . $e->getMessage());
    }

    try {
        // Push pending + pull remote data (syncMetadataOnly handles both)
        // Uses consolidated SyncQueueService with dependency ordering and conflict resolution
        $this->fullSync->syncMetadataOnly();

        // Log local patient count after sync
        try {
            $afterCount = \App\Domains\Patients\Models\Patient::count();
            Log::info('[NativeSyncController] Local patients AFTER sync: ' . $afterCount);
        } catch (\Throwable $e) {
            Log::warning('[NativeSyncController] Count after sync failed: ' . $e->getMessage());
        }

        return response()->json(['success' => true]);
    } catch (\Exception $e) {
        Log::error('NativeSyncController error: ' . $e->getMessage());
        return response()->json(['error' => $e->getMessage()], 500);
    }
}

    /**
     * Get the current API token from the active session or local DB.
     */
    private function getToken(): ?string
    {
        try {
            return app(\App\Services\Mobile\ApiService::class)->getToken();
        } catch (\Throwable $e) {
            try {
                return session('api_token_raw');
            } catch (\Throwable $se) {
                return null;
            }
        }
    }

    public function getStatus()
    {
try {
$pendingCount = $this->syncQueue->getPendingCount();

$lastSyncRow = DB::table('sync_states')->where('key', 'last_sync_at')->first();
$inProgressRow = DB::table('sync_states')->where('key', 'sync_in_progress')->first();

$lastSyncAt = $lastSyncRow ? json_decode($lastSyncRow->value, true) : null;
$syncInProgress = $inProgressRow ? (bool) json_decode($inProgressRow->value, true) : false;

return response()->json([
'success'          => true,
'pending_count'    => $pendingCount,
'last_sync_at'     => $lastSyncAt,
'sync_in_progress' => $syncInProgress,
]);
} catch (\Exception $e) {
Log::error('NativeSyncController::getStatus error: ' . $e->getMessage());
return response()->json(['error' => $e->getMessage()], 500);
}
}

/**
 * POST /api/native/sync/force
 *
 * Forces a full sync regardless of connectivity checks.
 */
public function forceSync(Request $request)
{
Log::info('NativeSyncController: Force sync requested.');

try {
return $this->sync($request);
} catch (\Exception $e) {
Log::error('NativeSyncController::forceSync error: ' . $e->getMessage());
return response()->json(['error' => $e->getMessage()], 500);
}
}
}
