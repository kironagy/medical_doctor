<?php

namespace App\Services;

use App\Models\FileCategory;
use App\Models\Patient;
use App\Models\PatientFile;
use App\Models\PatientVisit;
use App\Models\SyncQueueItem;
use App\Models\SyncState;
use App\Models\User;
use App\Sync\SyncHandlerRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;
use Throwable;

class OfflineSyncEngine
{
    private const MODELS = [
        'patients' => Patient::class,
        'patient_files' => PatientFile::class,
        'patient_visits' => PatientVisit::class,
        'file_categories' => FileCategory::class,
        'users' => User::class,
    ];

    private const TABLE_SYNC_ORDER = [
        'file_categories' => 1,
        'patients' => 2,
        'patient_visits' => 3,
        'patient_files' => 4,
        'users' => 5,
    ];

    public function __construct(
        private readonly MobileApiClient $api,
        private readonly SyncHandlerRegistry $registry
    ) {
    }

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

    public function initialSeed(string $token): void
    {
        if ($this->state('initialized')) {
            return;
        }

        $page = 1;
        $limit = 100;
        $hasMore = true;
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

    public function queue(string $table, string $operation, array $payload, ?string $recordUuid = null): SyncQueueItem
    {
        return SyncQueueItem::create([
            'table_name' => $table,
            'record_uuid' => $recordUuid ?? ($payload['uuid'] ?? null),
            'operation' => $operation,
            'payload' => $payload,
            'status' => 'pending',
        ]);
    }

    public function sync(string $token): array
    {
        $uploaded = $this->flushQueue($token);
        $downloaded = $this->pullChanges($token);

        return compact('uploaded', 'downloaded');
    }

    /**
     * Push pending local operations to the remote server.
     * Updates each queue item status individually based on remote application results.
     */
    public function flushQueue(string $token, int $limit = 100): int
    {
        $items = SyncQueueItem::whereIn('status', ['pending', 'retrying'])
            ->where(fn($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()))
            ->orderByRaw("CASE operation WHEN 'create' THEN 1 WHEN 'update' THEN 2 WHEN 'delete' THEN 3 ELSE 4 END")
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($items->isEmpty()) {
            return 0;
        }

        $successCount = 0;
        $totalItems = $items->count();

        Log::info("Starting offline queue sync. Found {$totalItems} pending operations.");

        foreach ($items as $index => $item) {
            $item->update(['status' => 'running']);
            $payload = $item->payload ?? [];
            $remaining = $totalItems - $index - 1;

            if ($item->table_name === 'patient_files' && $item->operation !== 'delete' && !empty($payload['file_path'])) {
                $relativePath = preg_replace('#^/storage/#', '', $payload['file_path']);
                $localPath = storage_path('app/public/' . $relativePath);

                if (file_exists($localPath)) {
                    $payload['data'] = base64_encode(file_get_contents($localPath));
                }
            }

            $operation = [
                'uuid' => $item->record_uuid,
                'table' => $item->table_name,
                'operation' => $item->operation,
                'payload' => $payload,
            ];

            $startedAt = microtime(true);

            try {
                // Send one-by-one to avoid bulk timeouts and isolated failures
                $response = $this->api->push($token, [$operation]);
                $res = $response['results'][0] ?? null;

                $durationMs = (int) ((microtime(true) - $startedAt) * 1000);

                if ($res) {
                    $status = $res['status'] ?? 'failed';
                    $error = $res['error'] ?? null;

                    if ($status === 'applied' || $status === 'deleted' || $status === 'conflict_server_won') {
                        $item->update([
                            'status' => 'completed',
                            'last_error' => null,
                        ]);
                        $successCount++;
                    } elseif ($status === 'skipped') {
                        $item->update([
                            'status' => 'skipped',
                            'last_error' => $error ?? 'Skipped by server sync handler.',
                        ]);
                        $successCount++;
                    } else {
                        $retry = $item->retry_count + 1;
                        $newStatus = $retry >= 10 ? 'failed' : 'retrying';
                        $item->update([
                            'status' => $newStatus,
                            'retry_count' => $retry,
                            'last_error' => $error ?? 'Server execution failed.',
                            'available_at' => now()->addSeconds(min(3600, 2 ** min($retry, 10))),
                        ]);
                    }

                    Log::info("Sync queue item processed.", [
                        'queue_id' => $item->id,
                        'table' => $item->table_name,
                        'uuid' => $item->record_uuid,
                        'operation' => $item->operation,
                        'status' => $status,
                        'duration_ms' => $durationMs,
                        'retry_count' => $item->retry_count,
                        'remaining_items' => $remaining,
                        'error' => $error
                    ]);
                } else {
                    $retry = $item->retry_count + 1;
                    $newStatus = $retry >= 10 ? 'failed' : 'retrying';
                    $item->update([
                        'status' => $newStatus,
                        'retry_count' => $retry,
                        'last_error' => 'No response status returned from remote server.',
                        'available_at' => now()->addSeconds(min(3600, 2 ** min($retry, 10))),
                    ]);

                    Log::warning("Sync queue item failed (No response status).", [
                        'queue_id' => $item->id,
                        'table' => $item->table_name,
                        'uuid' => $item->record_uuid,
                        'operation' => $item->operation,
                        'remaining_items' => $remaining,
                    ]);
                }
            } catch (Throwable $throwable) {
                $durationMs = (int) ((microtime(true) - $startedAt) * 1000);
                $retry = $item->retry_count + 1;
                $newStatus = $retry >= 10 ? 'failed' : 'retrying';
                
                $item->update([
                    'status' => $newStatus,
                    'retry_count' => $retry,
                    'last_error' => $throwable->getMessage(),
                    'available_at' => now()->addSeconds(min(3600, 2 ** min($retry, 10))),
                ]);

                Log::error("Sync queue item network error.", [
                    'queue_id' => $item->id,
                    'table' => $item->table_name,
                    'uuid' => $item->record_uuid,
                    'operation' => $item->operation,
                    'duration_ms' => $durationMs,
                    'retry_count' => $retry,
                    'remaining_items' => $remaining,
                    'message' => $throwable->getMessage(),
                    'exception' => get_class($throwable)
                ]);
            }
        }

        return $successCount;
    }

    public function pullChanges(string $token): int
    {
        $since = $this->state('last_sync_at');
        $page = 1;
        $limit = 100;
        $hasMore = true;
        $totalCount = 0;
        $serverTime = null;

        while ($hasMore) {
            $payload = $this->api->changes($token, $since, $page, $limit);
            
            if ($serverTime === null) {
                $serverTime = $payload['server_time'] ?? now()->toISOString();
            }

            $count = $this->applyTables($payload['tables'] ?? []);

            $totalCount += $count;
            $hasMore = $payload['has_more'] ?? false;
            $page++;
        }

        if ($serverTime !== null) {
            $this->setState('last_sync_at', $serverTime);
        }

        return $totalCount;
    }

    /**
     * Apply remote seed/changes locally.
     * Enforces relation table ordering and routes payloads through specific sync handlers.
     */
    private function applyTables(array $tables): int
    {
        $count = 0;

        // Sort tables based on model relations to avoid foreign key violations
        uksort($tables, function ($a, $b) {
            $orderA = self::TABLE_SYNC_ORDER[$a] ?? 99;
            $orderB = self::TABLE_SYNC_ORDER[$b] ?? 99;
            return $orderA <=> $orderB;
        });

        foreach ($tables as $table => $tableData) {
            if (!isset(self::MODELS[$table])) {
                continue;
            }

            $records = isset($tableData['records']) ? $tableData['records'] : $tableData;

            /** @var class-string<Model> $modelClass */
            $modelClass = self::MODELS[$table];
            $handler = $this->registry->getHandler($table);

            $modelClass::withoutEvents(function () use ($records, $table, $handler, &$count) {
                foreach ($records as $record) {
                    $uuid = $record['uuid'] ?? null;
                    if (!$uuid) {
                        continue;
                    }

                    $operation = !empty($record['deleted_at']) ? 'delete' : 'update';
                    
                    // Call apply on specific sync handler (isolated transaction)
                    $result = $handler->apply($operation, $record, $uuid);

                    if ($result['status'] === 'applied' || $result['status'] === 'deleted' || $result['status'] === 'conflict_server_won') {
                        $count++;
                    } elseif ($result['status'] === 'skipped') {
                        Log::info("Pull sync skipped a record for [{$table}] (uuid: {$uuid}): " . ($result['error'] ?? ''));
                    } else {
                        Log::warning("Pull sync failed to apply record for [{$table}] (uuid: {$uuid}): " . ($result['error'] ?? 'Unknown error'));
                    }
                }
            });
        }

        return $count;
    }

    private function state(string $key): mixed
    {
        return SyncState::find($key)?->value['data'] ?? null;
    }

    private function setState(string $key, mixed $value): void
    {
        SyncState::updateOrCreate(['key' => $key], ['value' => ['data' => $value]]);
    }
}
