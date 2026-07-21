<?php

namespace App\Services\Sync;

use App\Models\SyncQueueItem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PendingOperationsService
{
    /** Max retry attempts before marking as permanently failed */
    private const MAX_RETRIES = 5;

    /**
     * Map entity types to their local table names.
     */
    private const ENTITY_TABLE_MAP = [
        'Patient'       => 'patients',
        'PatientVisit'  => 'patient_visits',
        'PatientNote'   => 'patient_notes',
        'PatientFile'   => 'patient_files',
        'PatientShare'  => 'patient_shares',
    ];

    /**
     * Enqueue an operation into sync_queue with dedup check.
     */
    public function enqueue(
        string $entity,
        string $operation,
        ?string $recordUuid,
        ?array $payload,
        int $priority = 5
    ): SyncQueueItem {
        // Dedup: skip if a pending operation for the same record already exists
        if ($recordUuid && $this->hasPending($recordUuid, $operation)) {
            Log::info("[PendingOperationsService] Skipping duplicate enqueue: {$entity} {$operation} {$recordUuid}");
            
            // Return existing pending item
            $existing = SyncQueueItem::where('record_uuid', $recordUuid)
                ->where('operation', $operation)
                ->where('status', 'pending')
                ->first();
            if ($existing) {
                // Update payload with latest data
                $existing->update(['payload' => $payload]);
                return $existing;
            }
        }

        $tableName = self::ENTITY_TABLE_MAP[$entity] ?? strtolower($entity);

        $item = SyncQueueItem::create([
            'uuid'         => (string) Str::uuid(),
            'entity'       => $entity,
            'table_name'   => $tableName,
            'record_uuid'  => $recordUuid,
            'operation'    => $operation,
            'payload'      => $payload,
            'priority'     => $priority,
            'retry_count'  => 0,
            'status'       => 'pending',
            'last_error'   => null,
            'last_attempt_at' => null,
            'available_at' => now(),
        ]);

        Log::info("[PendingOperationsService] Enqueued {$entity} {$operation} (uuid: {$item->uuid})");

        return $item;
    }

    /**
     * Check if a pending operation exists for the given record.
     */
    public function hasPending(string $recordUuid, string $operation): bool
    {
        return SyncQueueItem::where('record_uuid', $recordUuid)
            ->where('operation', $operation)
            ->whereIn('status', ['pending', 'failed'])
            ->exists();
    }

    /**
     * Process pending operations sorted by priority and age.
     */
    public function processPending(int $batchSize = 50): \Illuminate\Database\Eloquent\Collection
    {
        $items = SyncQueueItem::whereIn('status', ['pending', 'failed'])
            ->where('retry_count', '<', self::MAX_RETRIES)
            ->orderBy('priority', 'asc')
            ->orderBy('created_at', 'asc')
            ->limit($batchSize)
            ->get();

        // Reset failed items to pending (avoids per-item events)
        $failedIds = $items->where('status', 'failed')->pluck('id');
        if ($failedIds->isNotEmpty()) {
            SyncQueueItem::whereIn('id', $failedIds)->update(['status' => 'pending']);
        }

        Log::info("[PendingOperationsService] Fetched {$items->count()} pending operations.");

        return $items;
    }

    /**
     * Mark an item as successfully synced or failed with retry logic.
     */
    public function markResult(SyncQueueItem $item, bool $success, ?string $errorMessage = null): void
    {
        $item->last_attempt_at = now();
        $item->retry_count = $item->retry_count + 1;

        if ($success) {
            $item->status = 'synced';
            $item->last_error = null;
            Log::info("[PendingOperationsService] Item {$item->uuid} marked as synced.");
        } else {
            if ($item->retry_count >= self::MAX_RETRIES) {
                $item->status = 'permanently_failed';
                Log::error("[PendingOperationsService] Item {$item->uuid} permanently failed after {$item->retry_count} attempts: {$errorMessage}");
            } else {
                $item->status = 'failed';
                Log::warning("[PendingOperationsService] Item {$item->uuid} failed (attempt {$item->retry_count}/" . self::MAX_RETRIES . "): {$errorMessage}");
            }
            $item->last_error = $errorMessage ?? 'Unknown error';
        }

        $item->save();
    }

    /**
     * Get count of pending items.
     */
    public function getPendingCount(): int
    {
        return SyncQueueItem::where('status', 'pending')->count();
    }

    /**
     * Get total backlog count (pending + retriable failed).
     */
    public function getBacklogCount(): int
    {
        return SyncQueueItem::whereIn('status', ['pending', 'failed'])
            ->where('retry_count', '<', self::MAX_RETRIES)
            ->count();
    }

    /**
     * Clean up old synced records.
     */
    public function clearSynced(int $olderThanDays = 7): int
    {
        $cutoff = now()->subDays($olderThanDays);
        $count = SyncQueueItem::where('status', 'synced')
            ->where('updated_at', '<', $cutoff)
            ->delete();
        Log::info("[PendingOperationsService] Cleared {$count} synced items older than {$olderThanDays} days.");
        return $count;
    }

    /**
     * Clean up permanently failed records.
     */
    public function clearFailed(int $olderThanDays = 30): int
    {
        $cutoff = now()->subDays($olderThanDays);
        $count = SyncQueueItem::where('status', 'permanently_failed')
            ->where('updated_at', '<', $cutoff)
            ->delete();
        Log::info("[PendingOperationsService] Cleared {$count} permanently failed items older than {$olderThanDays} days.");
        return $count;
    }
}
