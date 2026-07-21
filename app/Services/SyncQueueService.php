<?php

namespace App\Services;

use App\Models\SyncQueueItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SyncQueueService
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
     * Enqueue an operation into sync_queue.
     *
     * @param  string  $entity      One of the ENTITY_TABLE_MAP keys (e.g. 'Patient')
     * @param  string  $operation   'create' | 'update' | 'delete'
     * @param  string|null  $recordUuid  UUID of the affected local record
     * @param  array|null  $payload   The operation payload (JSON-encoded)
     * @param  int  $priority     Lower number = higher priority (default 5)
     */
    public function enqueueOperation(
        string $entity,
        string $operation,
        ?string $recordUuid,
        ?array $payload,
        int $priority = 5
    ): SyncQueueItem {
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

        $this->updateState([
            'pending_count' => $this->getPendingCount(),
        ]);

        Log::info("[SyncQueueService] Enqueued {$entity} {$operation} (uuid: {$item->uuid})");

        return $item;
    }

    /**
     * Mark a sync queue item as synced (or failed with retry logic).
     * Automatically marks as permanently_failed after MAX_RETRIES.
     */
    public function markItemResult(SyncQueueItem $item, bool $success, ?string $errorMessage = null): void
    {
        $item->last_attempt_at = now();
        $item->retry_count    = $item->retry_count + 1;

        if ($success) {
            $item->status     = 'synced';
            $item->last_error = null;
            $item->save();

            Log::info("[SyncQueueService] Item {$item->uuid} marked as synced.");
        } else {
            // Check if exceeded max retries
            if ($item->retry_count >= self::MAX_RETRIES) {
                $item->status = 'permanently_failed';
                Log::error("[SyncQueueService] Item {$item->uuid} permanently failed after {$item->retry_count} attempts: {$errorMessage}");
            } else {
                $item->status = 'failed';
                Log::warning("[SyncQueueService] Item {$item->uuid} failed (attempt {$item->retry_count}/" . self::MAX_RETRIES . "): {$errorMessage}");
            }
            $item->last_error = $errorMessage ?? 'Unknown error';
            $item->save();
        }

        $this->updateState([
            'pending_count' => $this->getPendingCount(),
        ]);
    }

    /**
     * Process all pending operations in priority + oldest-first order.
     * Does NOT automatically push to the remote API; callers iterate the
     * returned items and apply their own push logic, then call markItemResult.
     *
     * @param  int  $batchSize  Max items to pull in one call (default 50)
     */
    public function processPendingOperations(int $batchSize = 50): \Illuminate\Database\Eloquent\Collection
    {
        $this->updateState(['sync_in_progress' => true]);

        // Include both 'pending' and 'failed' items (retry failed ones)
        $items = SyncQueueItem::whereIn('status', ['pending', 'failed'])
            ->where('retry_count', '<', self::MAX_RETRIES)
            ->orderBy('priority', 'asc')
            ->orderBy('created_at', 'asc')
            ->limit($batchSize)
            ->get();

        // Batch reset failed items to pending (avoids per-item events)
        $failedIds = $items->where('status', 'failed')->pluck('id');
        if ($failedIds->isNotEmpty()) {
            SyncQueueItem::whereIn('id', $failedIds)->update(['status' => 'pending']);
        }

        Log::info("[SyncQueueService] Fetched {$items->count()} pending operations for processing.");

        $this->updateState([
            'sync_in_progress' => false,
            'last_sync_at'     => now()->toDateTimeString(),
            'pending_count'    => $this->getPendingCount(),
        ]);

        return $items;
    }

    /**
     * Return the count of truly pending items (never attempted yet).
     */
    public function getPendingCount(): int
    {
        return SyncQueueItem::where('status', 'pending')->count();
    }

    /**
     * Return the count of items needing processing (pending + retriable failed).
     */
    public function getTotalBacklogCount(): int
    {
        return SyncQueueItem::whereIn('status', ['pending', 'failed'])
            ->where('retry_count', '<', self::MAX_RETRIES)
            ->count();
    }

    /**
     * Get count of permanently failed items.
     */
    public function getPermanentlyFailedCount(): int
    {
        return SyncQueueItem::where('status', 'permanently_failed')->count();
    }

    /**
     * Clean up permanently failed items older than the given days.
     */
    public function clearPermanentlyFailed(int $olderThanDays = 30): int
    {
        $cutoff = now()->subDays($olderThanDays);
        $count = SyncQueueItem::where('status', 'permanently_failed')
            ->where('updated_at', '<', $cutoff)
            ->delete();

        Log::info("[SyncQueueService] Cleared {$count} permanently failed items older than {$olderThanDays} days.");
        return $count;
    }

    /**
     * Delete synced records older than the given number of days (default 7).
     */
    public function clearSyncedOperations(int $olderThanDays = 7): int
    {
        $cutoff = now()->subDays($olderThanDays);

        $count = SyncQueueItem::where('status', 'synced')
            ->where('updated_at', '<', $cutoff)
            ->count();

        SyncQueueItem::where('status', 'synced')
            ->where('updated_at', '<', $cutoff)
            ->delete();

        $this->updateState([
            'pending_count' => $this->getPendingCount(),
        ]);

        Log::info("[SyncQueueService] Cleared {$count} synced operations older than {$olderThanDays} days.");

        return $count;
    }

    /**
     * Write one or more key/value pairs to the sync_states table.
     * Values are stored as JSON.
     */
    private function updateState(array $values): void
    {
        foreach ($values as $key => $value) {
            if (is_array($value) || is_object($value)) {
                $jsonValue = json_encode($value);
            } else {
                $jsonValue = (string) $value;
            }

            $exists = DB::table('sync_states')->where('key', $key)->exists();
            if ($exists) {
                DB::table('sync_states')->where('key', $key)->update([
                    'value' => $jsonValue,
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('sync_states')->insert([
                    'key' => $key,
                    'value' => $jsonValue,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
