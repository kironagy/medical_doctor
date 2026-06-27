<?php

namespace App\Services;

use App\Models\FileCategory;
use App\Models\Patient;
use App\Models\PatientFile;
use App\Models\PatientVisit;
use App\Models\SyncJob;
use App\Models\SyncQueueItem;
use App\Models\SyncState;
use App\Models\User;
use App\Models\Concerns\HasSyncIdentity;
use App\Sync\SyncHandlerRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Throwable;

class OfflineSyncEngine
{
    private const MODELS = [
        'file_categories' => FileCategory::class,
        'users'           => User::class,
        'patients'        => Patient::class,
        'patient_visits'  => PatientVisit::class,
        'patient_files'   => PatientFile::class,
    ];

    private const TABLE_SYNC_ORDER = [
        'file_categories' => 1,
        'users'           => 2,
        'patients'        => 3,
        'patient_visits'  => 4,
        'patient_files'   => 5,
    ];

    public function __construct(
        private readonly MobileApiClient    $api,
        private readonly SyncHandlerRegistry $registry
    ) {
    }

    // ─── Local Database Initialization ────────────────────────────────────────

    public function initializeLocalDatabase(): void
    {
        if (config('database.default') !== 'sqlite') {
            return;
        }

        $database = config('database.connections.sqlite.database');
        if ($database && $database !== ':memory:' && !file_exists($database)) {
            touch($database);
            Artisan::call('migrate', ['--force' => true, '--seed' => true]);
        }
    }

    // ─── Initial Seed ─────────────────────────────────────────────────────────

    public function initialSeed(string $token): void
    {
        if ($this->state('initialized')) {
            return;
        }

        $page       = 1;
        $limit      = 100;
        $hasMore    = true;
        $serverTime = null;

        while ($hasMore) {
            $payload = $this->api->seed($token, $page, $limit);

            if ($serverTime === null) {
                $serverTime = $payload['server_time'] ?? now()->toISOString();
            }

            $this->applyTables($payload['tables'] ?? []);

            $hasMore = $payload['has_more'] ?? false;
            $page++;
        }

        $this->setState('last_sync_at', $serverTime);
        $this->setState('initialized', true);
    }

    // ─── Queue New Offline Operation ──────────────────────────────────────────

    public function queue(string $table, string $operation, array $payload, ?string $recordUuid = null): SyncQueueItem
    {
        return SyncQueueItem::create([
            'table_name'  => $table,
            'record_uuid' => $recordUuid ?? ($payload['uuid'] ?? null),
            'operation'   => $operation,
            'payload'     => $payload,
            'status'      => 'pending',
            // entity, priority auto-set by SyncQueueItem::booted()
        ]);
    }

    // ─── Main Sync Entry (Called by ProcessSyncJob) ────────────────────────────

    /**
     * Execute a full sync cycle: upload pending queue → download changes.
     * Updates the provided SyncJob with live progress.
     *
     * @param string  $token   Bearer token for the remote API.
     * @param SyncJob $syncJob The job record tracking this run.
     */
    public function sync(string $token, SyncJob $syncJob): array
    {
        $syncJob->markProcessing();

        // Count total pending items before we start
        $totalPending = SyncQueueItem::pendingAndDue()->count();
        $syncJob->update(['total_items' => $totalPending]);

        $uploaded  = $this->flushQueue($token, $syncJob);
        $downloaded = $this->pullChanges($token);

        return compact('uploaded', 'downloaded');
    }

    // ─── Upload Queue (Chunk-based) ────────────────────────────────────────────

    /**
     * Process all pending offline operations one-by-one in ordered chunks.
     *
     * Key properties:
     * - chunkById(50): Never loads more than 50 items into memory.
     * - One HTTP request per item: Isolates failures at the record level.
     * - Ordering: priority ASC → create → update → delete → id ASC.
     * - Exceptions per item are caught and never abort the chunk loop.
     */
    public function flushQueue(string $token, ?SyncJob $syncJob = null, int $chunkSize = 50): int
    {
        $successCount = 0;
        $index        = 0;

        $totalPending = SyncQueueItem::pendingAndDue()->count();

        if ($totalPending === 0) {
            Log::info('sync.flush_queue: no pending items.');
            return 0;
        }

        Log::info("sync.flush_queue: starting. Total pending items: {$totalPending}.");

        SyncQueueItem::pendingAndDue()
            ->orderedByPriority()
            ->chunkById($chunkSize, function ($chunk) use (
                $token, $syncJob, &$successCount, &$index, $totalPending
            ) {
                foreach ($chunk as $item) {
                    $remaining = $totalPending - $index - 1;
                    $result    = $this->processSingleItem($item, $token);

                    if (in_array($result['status'] ?? '', ['completed', 'skipped'])) {
                        $successCount++;
                        $syncJob?->incrementProcessed();
                    } else {
                        $syncJob?->incrementFailed();
                    }

                    Log::info('sync.item.processed', [
                        'queue_id'        => $item->id,
                        'entity'          => $item->entity,
                        'uuid'            => $item->record_uuid,
                        'operation'       => $item->operation,
                        'status'          => $result['status'] ?? 'unknown',
                        'duration_ms'     => $result['duration_ms'] ?? null,
                        'retry_count'     => $item->retry_count,
                        'remaining_items' => $remaining,
                        'error'           => $result['error'] ?? null,
                    ]);

                    $index++;
                }
            });

        Log::info("sync.flush_queue: finished. Uploaded: {$successCount} / {$totalPending}.");

        return $successCount;
    }

    /**
     * Process a single SyncQueueItem: build the payload, push to server, update status.
     */
    private function processSingleItem(SyncQueueItem $item, string $token): array
    {
        $item->markRunning();

        $payload = $item->payload ?? [];

        // Encode binary files as base64 for transport
        if (
            $item->table_name === 'patient_files'
            && $item->operation !== 'delete'
            && !empty($payload['file_path'])
        ) {
            $relativePath = preg_replace('#^/storage/#', '', $payload['file_path']);
            $localPath    = storage_path('app/public/' . $relativePath);

            if (file_exists($localPath)) {
                $payload['data'] = base64_encode(file_get_contents($localPath));
            }
        }

        $operation = [
            'uuid'      => $item->record_uuid,
            'table'     => $item->table_name,
            'operation' => $item->operation,
            'payload'   => $payload,
        ];

        $startedAt = microtime(true);

        try {
            $response   = $this->api->push($token, [$operation]);
            $res        = $response['results'][0] ?? null;
            $durationMs = (int) ((microtime(true) - $startedAt) * 1000);

            if (!$res) {
                $item->markFailed('No result status returned from server.');
                return ['status' => 'failed', 'error' => 'No result status.', 'duration_ms' => $durationMs];
            }

            $status = $res['status'] ?? 'failed';
            $error  = $res['error'] ?? null;

            if (in_array($status, ['applied', 'deleted', 'conflict_server_won'])) {
                $item->markCompleted();
                return ['status' => 'completed', 'error' => null, 'duration_ms' => $durationMs];
            }

            if ($status === 'skipped') {
                $item->markSkipped($error ?? 'Skipped by server sync handler.');
                return ['status' => 'skipped', 'error' => $error, 'duration_ms' => $durationMs];
            }

            // Server returned failed
            $item->markFailed($error ?? 'Server execution failed.');
            return ['status' => 'retrying', 'error' => $error, 'duration_ms' => $durationMs];

        } catch (Throwable $throwable) {
            $durationMs = (int) ((microtime(true) - $startedAt) * 1000);
            $item->markFailed($throwable->getMessage());

            Log::error('sync.item.network_error', [
                'queue_id'    => $item->id,
                'entity'      => $item->entity,
                'uuid'        => $item->record_uuid,
                'operation'   => $item->operation,
                'duration_ms' => $durationMs,
                'message'     => $throwable->getMessage(),
                'exception'   => get_class($throwable),
            ]);

            return ['status' => 'failed', 'error' => $throwable->getMessage(), 'duration_ms' => $durationMs];
        }
    }

    // ─── Download Changes (Cursor-based) ──────────────────────────────────────

    /**
     * Pull delta changes from the remote server using cursor-based pagination.
     *
     * Cursor pagination avoids COUNT(*) queries and efficiently handles
     * millions of records without pagination drift or memory overload.
     */
    public function pullChanges(string $token): int
    {
        $since      = $this->state('last_sync_at');
        $cursor     = null;
        $hasMore    = true;
        $totalCount = 0;
        $serverTime = null;
        $page       = 0;

        Log::info('sync.pull_changes: starting.', ['since' => $since]);

        while ($hasMore) {
            $payload = $this->api->changes($token, $since, $cursor);

            if ($serverTime === null) {
                $serverTime = $payload['server_time'] ?? now()->toISOString();
            }

            $count = $this->applyTables($payload['tables'] ?? []);
            $totalCount += $count;

            $hasMore = $payload['has_more'] ?? false;
            $cursor  = $payload['next_cursor'] ?? null;
            $page++;

            Log::info("sync.pull_changes: page {$page} applied {$count} records.", [
                'has_more'    => $hasMore,
                'next_cursor' => $cursor ? '[present]' : null,
            ]);

            // Safety: if server says has_more but gives no cursor, stop
            if ($hasMore && !$cursor) {
                Log::warning('sync.pull_changes: has_more=true but no cursor returned. Stopping.');
                break;
            }
        }

        if ($serverTime !== null) {
            $this->setState('last_sync_at', $serverTime);
        }

        Log::info("sync.pull_changes: finished. Total downloaded: {$totalCount}.");

        return $totalCount;
    }

    // ─── Apply Remote Tables Locally ──────────────────────────────────────────

    /**
     * Apply a batch of records from the server into the local SQLite database.
     *
     * Uses HasSyncIdentity::$applyingRemoteChanges = true to prevent
     * downloaded records from being re-enqueued into the upload queue.
     */
    private function applyTables(array $tables): int
    {
        $count = 0;

        // Sort by foreign key dependency order
        uksort($tables, function ($a, $b) {
            $orderA = self::TABLE_SYNC_ORDER[$a] ?? 99;
            $orderB = self::TABLE_SYNC_ORDER[$b] ?? 99;
            return $orderA <=> $orderB;
        });

        // Suppress upload-queue events while applying remote data
        HasSyncIdentity::$applyingRemoteChanges = true;

        try {
            foreach ($tables as $table => $tableData) {
                if (!isset(self::MODELS[$table])) {
                    continue;
                }

                $records = $tableData['records'] ?? $tableData;

                /** @var class-string<Model> $modelClass */
                $modelClass = self::MODELS[$table];
                $handler    = $this->registry->getHandler($table);

                $modelClass::withoutEvents(function () use ($records, $table, $handler, &$count) {
                    foreach ($records as $record) {
                        $uuid = $record['uuid'] ?? null;
                        if (!$uuid) {
                            continue;
                        }

                        $operation = !empty($record['deleted_at']) ? 'delete' : 'update';
                        $result    = $handler->apply($operation, $record, $uuid);

                        if (in_array($result['status'], ['applied', 'deleted', 'conflict_server_won'])) {
                            $count++;
                        } elseif ($result['status'] === 'skipped') {
                            Log::info("sync.pull: skipped [{$table}] uuid={$uuid}: " . ($result['error'] ?? ''));
                        } else {
                            Log::warning("sync.pull: failed [{$table}] uuid={$uuid}: " . ($result['error'] ?? 'unknown'));
                        }
                    }
                });
            }
        } finally {
            // Always restore the flag — even if an exception occurred
            HasSyncIdentity::$applyingRemoteChanges = false;
        }

        return $count;
    }

    // ─── State Helpers ────────────────────────────────────────────────────────

    private function state(string $key): mixed
    {
        return SyncState::find($key)?->value['data'] ?? null;
    }

    private function setState(string $key, mixed $value): void
    {
        SyncState::updateOrCreate(['key' => $key], ['value' => ['data' => $value]]);
    }
}
