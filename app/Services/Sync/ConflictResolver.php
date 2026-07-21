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

    /**
     * Check if a local record has pending sync operations.
     */
    public function hasPendingChanges(string $recordUuid): bool
    {
        return \App\Models\SyncQueueItem::where('record_uuid', $recordUuid)
            ->whereIn('status', ['pending', 'failed'])
            ->exists();
    }
}
