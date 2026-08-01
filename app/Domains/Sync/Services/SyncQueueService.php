<?php

namespace App\Domains\Sync\Services;

use App\Domains\Sync\Models\SyncQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class SyncQueueService
{
    /**
     * Push a new modification operation onto the sync queue with smart operation merging.
     */
    public function push(
        string $entityType,
        string $entityUuid,
        string $operation,
        array $payload = [],
        int $payloadVersion = 1
    ): ?SyncQueue {
        try {
            $operation = strtolower($operation);

            // Fetch any existing pending/failed queue item for this entity
            $existing = SyncQueue::where('entity_uuid', $entityUuid)
                ->whereIn('status', ['pending', 'failed'])
                ->first();

            if ($existing) {
                // Rule A: Create -> Update === Update Create payload
                if ($existing->operation === 'create' && $operation === 'update') {
                    $existing->update([
                        'payload' => array_merge($existing->payload ?? [], $payload),
                        'payload_version' => $payloadVersion,
                        'updated_at' => now(),
                    ]);
                    return $existing;
                }

                // Rule B: Create -> Delete === Cancel out queue item completely
                if ($existing->operation === 'create' && $operation === 'delete') {
                    $existing->delete();
                    return null;
                }

                // Rule C: Update -> Delete === Replace with Delete
                if ($existing->operation === 'update' && $operation === 'delete') {
                    $existing->update([
                        'operation' => 'delete',
                        'payload' => null,
                        'status' => 'pending',
                        'updated_at' => now(),
                    ]);
                    return $existing;
                }

                // Same operation update
                if ($existing->operation === $operation) {
                    $existing->update([
                        'payload' => array_merge($existing->payload ?? [], $payload),
                        'payload_version' => $payloadVersion,
                        'status' => 'pending',
                        'updated_at' => now(),
                    ]);
                    return $existing;
                }
            }

            return SyncQueue::create([
                'uuid'            => (string) Str::uuid(),
                'entity_type'     => strtolower($entityType),
                'entity_uuid'     => $entityUuid,
                'operation'       => $operation,
                'payload_version' => $payloadVersion,
                'payload'         => $payload,
                'status'          => 'pending',
                'retry_count'     => 0,
            ]);
        } catch (Throwable $e) {
            Log::error('[SyncQueueService] Failed to push item to sync_queue: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get all pending queue items, automatically recovering stuck 'processing' items (> 15 mins).
     */
    public function getPending(int $limit = 50)
    {
        // ── Auto-Recovery for Stuck Processing Items (App crash recovery) ────
        SyncQueue::where('status', 'processing')
            ->where('last_attempt_at', '<', now()->subMinutes(15))
            ->update([
                'status' => 'pending',
                'last_error' => 'Reset from stuck processing state after timeout',
            ]);

        return SyncQueue::whereIn('status', ['pending', 'failed'])
            ->where('retry_count', '<', 5)
            ->orderBy('created_at', 'asc')
            ->take($limit)
            ->get();
    }

    /**
     * Mark a queue item as processing.
     */
    public function markProcessing(int $id): void
    {
        SyncQueue::where('id', $id)->update([
            'status' => 'processing',
            'last_attempt_at' => now(),
        ]);
    }

    /**
     * Mark a queue item as successfully synced.
     */
    public function markSynced(int $id): void
    {
        SyncQueue::where('id', $id)->update([
            'status' => 'synced',
            'updated_at' => now(),
        ]);
    }

    /**
     * Mark a queue item as failed with an error message.
     */
    public function markFailed(int $id, string $error): void
    {
        SyncQueue::where('id', $id)->increment('retry_count', 1, [
            'status' => 'failed',
            'last_error' => substr($error, 0, 1000),
            'updated_at' => now(),
        ]);
    }

    /**
     * Mark a queue item as having a version conflict.
     */
    public function markConflict(int $id, string $error): void
    {
        SyncQueue::where('id', $id)->update([
            'status' => 'conflict',
            'last_error' => substr($error, 0, 1000),
            'updated_at' => now(),
        ]);
    }

    /**
     * Get queue statistics summary.
     */
    public function getQueueStats(): array
    {
        return [
            'pending'    => SyncQueue::where('status', 'pending')->count(),
            'processing' => SyncQueue::where('status', 'processing')->count(),
            'failed'     => SyncQueue::where('status', 'failed')->count(),
            'conflict'   => SyncQueue::where('status', 'conflict')->count(),
            'synced'     => SyncQueue::where('status', 'synced')->count(),
            'total'      => SyncQueue::whereIn('status', ['pending', 'failed', 'conflict'])->count(),
        ];
    }
}
