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

    /** Max pending items before warning */
    private const MAX_PENDING_WARN_THRESHOLD = 1000;

    /**
     * Dependency ordering for entity types.
     * Patients must be processed before child records (files, notes, visits).
     */
    private const ENTITY_DEPENDENCY_ORDER = [
        'Patient'       => 0,
        'PatientShare'  => 1,
        'PatientVisit'  => 2,
        'PatientNote'   => 2,
        'PatientFile'   => 2,
    ];

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
     * Enqueue an operation into sync_queue with dedup support.
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
        // Dedup: skip if a pending operation for the same record already exists
        if ($recordUuid && $this->hasPending($recordUuid, $operation)) {
            Log::info("[SyncQueueService] Skipping duplicate enqueue: {$entity} {$operation} {$recordUuid}");

            $existing = SyncQueueItem::where('record_uuid', $recordUuid)
                ->where('operation', $operation)
                ->where('status', 'pending')
                ->first();

            if ($existing) {
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

        $pendingCount = $this->getPendingCount();

        $this->updateState([
            'pending_count' => $pendingCount,
        ]);

        if ($pendingCount > self::MAX_PENDING_WARN_THRESHOLD) {
            Log::warning("[SyncQueueService] Queue size warning: {$pendingCount} pending items");
        }

        Log::info("[SyncQueueService] Enqueued {$entity} {$operation} (uuid: {$item->uuid})");

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
     * Process all pending operations ordered by dependency (patients first, then child records),
     * then by priority and creation time.
     *
     * @param  int  $batchSize  Max items to pull in one call (default 50)
     */
    public function processPendingOperations(int $batchSize = 50): \Illuminate\Database\Eloquent\Collection
    {
        $this->updateState(['sync_in_progress' => true]);

        // Include both 'pending' and 'failed' items (retry failed ones)
        // Order by: dependency order (patients first), then priority, then creation time
        $items = SyncQueueItem::whereIn('status', ['pending', 'failed'])
            ->where('retry_count', '<', self::MAX_RETRIES)
            ->get()
            ->sortBy([
                fn($a, $b) => (self::ENTITY_DEPENDENCY_ORDER[$a->entity] ?? 99) <=> (self::ENTITY_DEPENDENCY_ORDER[$b->entity] ?? 99),
                fn($a, $b) => $a->priority <=> $b->priority,
                fn($a, $b) => $a->created_at <=> $b->created_at,
            ])
            ->take($batchSize);

        // Batch reset failed items to pending (avoids per-item events)
        $failedIds = $items->where('status', 'failed')->pluck('id');
        if ($failedIds->isNotEmpty()) {
            SyncQueueItem::whereIn('id', $failedIds)->update(['status' => 'pending']);
        }

        Log::info("[SyncQueueService] Fetched {$items->count()} pending operations for processing.");

        return $items;
    }

    /**
     * Get the count of items needing processing (pending + retriable failed).
     */
    public function getTotalBacklogCount(): int
    {
        return SyncQueueItem::whereIn('status', ['pending', 'failed'])
            ->where('retry_count', '<', self::MAX_RETRIES)
            ->count();
    }

    /**
     * Group items by dependency sets:
     * Returns items grouped by entity type dependency level.
     * Level 0 (patients) must be processed before level 1+ (child records).
     */
    public function getItemsGroupedByDependency(): array
    {
        $items = SyncQueueItem::whereIn('status', ['pending', 'failed'])
            ->where('retry_count', '<', self::MAX_RETRIES)
            ->get();

        $grouped = [];
        foreach ($items as $item) {
            $level = self::ENTITY_DEPENDENCY_ORDER[$item->entity] ?? 99;
            $grouped[$level][] = $item;
        }

        ksort($grouped);

        return $grouped;
    }

    /**
     * Return the count of truly pending items (never attempted yet).
     */
    public function getPendingCount(): int
    {
        return SyncQueueItem::where('status', 'pending')->count();
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

    /**
     * Sync semaphore using database-backed lock instead of in-memory static flag.
     * This survives process crashes because:
     * 1. The lock is stored in the sync_states table (persistent)
     * 2. We use a heartbeat-based expiry mechanism
     * 3. Old locks are automatically released after LOCK_TTL seconds
     */
    private const LOCK_TTL = 300;

    public function acquireLock(): bool
    {
        $lockKey = 'sync_in_progress';
        $lockTimeKey = 'sync_lock_acquired_at';

        $existing = DB::table('sync_states')->where('key', $lockKey)->first();
        $lockValue = $existing ? json_decode($existing->value) : false;

        if ($lockValue === true) {
            $acquiredAt = DB::table('sync_states')->where('key', $lockTimeKey)->first();
            $acquiredTime = $acquiredAt ? json_decode($acquiredAt->value) : null;

            if ($acquiredTime && now()->diffInSeconds(new \Carbon\Carbon($acquiredTime)) < self::LOCK_TTL) {
                return false;
            }

            Log::warning('[SyncQueueService] Stale lock detected, force-releasing');
            $this->releaseLock();
        }

        $this->updateState([
            $lockKey => true,
            $lockTimeKey => now()->toIso8601String(),
        ]);

        return true;
    }

    public function releaseLock(): void
    {
        $this->updateState([
            'sync_in_progress' => false,
            'sync_lock_acquired_at' => null,
            'last_sync_at' => now()->toDateTimeString(),
        ]);
    }
}
