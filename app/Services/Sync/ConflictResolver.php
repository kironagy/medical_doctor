<?php

namespace App\Services\Sync;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ConflictResolver
{
    /**
     * Resolve a conflict between local and remote records.
     * Strategy: Last-Write-Wins (LWW).
     *
     * Returns 'local' if local changes should be kept,
     * 'remote' if remote changes should be kept,
     * or 'merge' if both can be merged.
     */
    public function resolve(
        ?string $localUpdatedAt,
        ?string $remoteUpdatedAt,
        bool $hasLocalPendingChanges = false
    ): string {
        // If there are pending local changes that haven't been pushed yet,
        // the local version should be kept to avoid data loss
        if ($hasLocalPendingChanges) {
            return 'local';
        }

        if (!$localUpdatedAt && !$remoteUpdatedAt) {
            return 'remote'; // No timestamps, trust remote
        }

        if (!$localUpdatedAt) {
            return 'remote'; // No local timestamp, trust remote
        }

        if (!$remoteUpdatedAt) {
            return 'local'; // No remote timestamp, trust local
        }

        try {
            $localTime = new Carbon($localUpdatedAt);
            $remoteTime = new Carbon($remoteUpdatedAt);

            // Remote is newer → use remote
            if ($remoteTime->gt($localTime)) {
                return 'remote';
            }

            // Local is newer (or tie) → use local
            return 'local';
        } catch (\Exception $e) {
            Log::warning('[ConflictResolver] Failed to parse timestamps, defaulting to remote: ' . $e->getMessage());
            return 'remote';
        }
    }

    /**
     * Simple timestamp comparison helper.
     * Returns true if the local record has a newer timestamp than the remote.
     */
    public function isLocalNewer(?string $localUpdatedAt, ?string $remoteUpdatedAt): bool
    {
        return $this->resolve($localUpdatedAt, $remoteUpdatedAt) === 'local';
    }

    /** Cache for the last_sync_at timestamp to avoid repeated DB queries during sync cycles. */
    private static ?Carbon $cachedLastSyncAt = null;

    /**
     * Check if a local record has pending changes that haven't been synced yet.
     *
     * Checks two conditions:
     * 1. Is there a pending/failed queue item for this record?
     * 2. Was the record modified locally (client_updated_at) after the last successful sync?
     *    This catches the case where a sync item was already processed (status='synced')
     *    but the record was then modified locally before the next pull sync — without
     *    creating a new queue item.
     *
     * @param  string  $recordUuid  UUID of the local record
     * @param  string|null  $localUpdatedAt  client_updated_at timestamp of the local record
     * @return bool  True if the local record has changes not yet pushed to remote
     */
    public function hasPendingChanges(string $recordUuid, ?string $localUpdatedAt = null): bool
    {
        // Check 1: Does the sync queue have pending/failed operations for this record?
        $hasQueueItem = \App\Models\SyncQueueItem::where('record_uuid', $recordUuid)
            ->whereIn('status', ['pending', 'failed'])
            ->exists();

        if ($hasQueueItem) {
            return true;
        }

        // Check 2: Was the record modified locally after the last successful sync?
        // This catches recently-synced records that were then edited locally.
        if ($localUpdatedAt) {
            try {
                $localTime = new Carbon($localUpdatedAt);

                // Cache last_sync_at to avoid repeated DB queries during large sync cycles
                if (self::$cachedLastSyncAt === null) {
                    $lastSyncRow = \Illuminate\Support\Facades\DB::table('sync_states')
                        ->where('key', 'last_sync_at')
                        ->first();
                    if ($lastSyncRow && !empty($lastSyncRow->value)) {
                        $lastSyncTime = json_decode($lastSyncRow->value);
                        if ($lastSyncTime) {
                            self::$cachedLastSyncAt = new Carbon($lastSyncTime);
                        }
                    }
                }

                if (self::$cachedLastSyncAt !== null && $localTime->gt(self::$cachedLastSyncAt)) {
                    Log::info("[ConflictResolver] Local record {$recordUuid} was modified after last sync (local: {$localUpdatedAt}, last_sync: " . self::$cachedLastSyncAt->toIso8601String() . ")");
                    return true;
                }
            } catch (\Exception $e) {
                Log::warning('[ConflictResolver] Failed to check client_updated_at: ' . $e->getMessage());
            }
        }

        return false;
    }

    /**
     * Reset the cached last_sync_at value. Called at the start of each sync cycle
     * to ensure fresh data on subsequent cycles.
     */
    public static function resetLastSyncCache(): void
    {
        self::$cachedLastSyncAt = null;
    }
}
