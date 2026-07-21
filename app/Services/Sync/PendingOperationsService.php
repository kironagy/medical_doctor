<?php

namespace App\Services\Sync;

use App\Models\SyncQueueItem;
use App\Services\SyncQueueService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * @deprecated Use App\Services\SyncQueueService instead.
 * This service is kept for migration purposes only.
 * All functionality has been consolidated into SyncQueueService
 * with improved dedup, dependency ordering, and database-backed locking.
 */
class PendingOperationsService
{
    private SyncQueueService $syncQueue;

    public function __construct(SyncQueueService $syncQueue)
    {
        $this->syncQueue = $syncQueue;
    }

    public function enqueue(
        string $entity,
        string $operation,
        ?string $recordUuid,
        ?array $payload,
        int $priority = 5
    ): SyncQueueItem {
        Log::warning('[PendingOperationsService] DEPRECATED: use SyncQueueService::enqueueOperation()');
        return $this->syncQueue->enqueueOperation($entity, $operation, $recordUuid, $payload, $priority);
    }

    public function hasPending(string $recordUuid, string $operation): bool
    {
        return $this->syncQueue->hasPending($recordUuid, $operation);
    }

    public function processPending(int $batchSize = 50): \Illuminate\Database\Eloquent\Collection
    {
        Log::warning('[PendingOperationsService] DEPRECATED: use SyncQueueService::processPendingOperations()');
        return $this->syncQueue->processPendingOperations($batchSize);
    }

    public function markResult(SyncQueueItem $item, bool $success, ?string $errorMessage = null): void
    {
        Log::warning('[PendingOperationsService] DEPRECATED: use SyncQueueService::markItemResult()');
        $this->syncQueue->markItemResult($item, $success, $errorMessage);
    }

    public function getPendingCount(): int
    {
        return $this->syncQueue->getPendingCount();
    }

    public function getBacklogCount(): int
    {
        return $this->syncQueue->getTotalBacklogCount();
    }

    public function clearSynced(int $olderThanDays = 7): int
    {
        return $this->syncQueue->clearSyncedOperations($olderThanDays);
    }

    public function clearFailed(int $olderThanDays = 30): int
    {
        return $this->syncQueue->clearPermanentlyFailed($olderThanDays);
    }
}
