<?php

namespace App\Sync\Handlers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

abstract class BaseSyncHandler implements SyncHandlerInterface
{
    /**
     * The model class managed by this handler.
     *
     * @var class-string<Model>
     */
    protected string $modelClass;

    /**
     * Common fields to exclude from upsert/create payloads.
     */
    protected array $excludedFields = [
        'id',
        'created_at',
        'updated_at',
        'deleted_at'
    ];

    /**
     * Find a model by its UUID, including trashed models if soft delete is supported.
     */
    protected function findModelByUuid(string $uuid): ?Model
    {
        $instance = new $this->modelClass;
        $query = $this->modelClass::query();

        if (Schema::hasColumn($instance->getTable(), 'deleted_at')) {
            $query->withTrashed();
        }

        return $query->where('uuid', $uuid)->first();
    }

    /**
     * Determine conflict resolution (who wins).
     */
    public function resolveConflict(Model $model, array $payload): bool
    {
        $serverTime = $model->client_updated_at ?? $model->updated_at;
        $incomingTimeStr = $payload['client_updated_at'] ?? null;

        if (!$incomingTimeStr) {
            return true; // Incoming wins if it has no time indicator
        }

        $incomingTime = Carbon::parse($incomingTimeStr);

        // If local/server time is newer than the client's payload, server wins
        if ($serverTime && $serverTime->greaterThan($incomingTime)) {
            return false;
        }

        return true;
    }

    /**
     * Clean payload by removing auto-increment/unnecessary attributes.
     */
    protected function cleanPayload(array $payload): array
    {
        foreach ($this->excludedFields as $field) {
            unset($payload[$field]);
        }
        return $payload;
    }

    /**
     * Main flow to validate, transform, resolve conflict and apply DB modifications safely.
     */
    public function apply(string $operation, array $payload, ?string $uuid = null): array
    {
        $action = strtolower($operation);
        $uuid = $uuid ?: ($payload['uuid'] ?? null);

        if (!$uuid) {
            $err = 'Sync operation failed: missing record UUID.';
            Log::warning($err, ['payload' => $payload]);
            return ['uuid' => null, 'status' => 'failed', 'error' => $err, 'id' => null];
        }

        try {
            $model = $this->findModelByUuid($uuid);

            // 1. Validation Before SQL
            $errors = $this->validate($payload, $action, $model);
            if (!empty($errors)) {
                $err = 'Validation failed: ' . json_encode($errors, JSON_UNESCAPED_UNICODE);
                Log::warning("Sync validation failed for {$uuid} in " . (new $this->modelClass)->getTable(), [
                    'uuid' => $uuid,
                    'errors' => $errors
                ]);
                return ['uuid' => $uuid, 'status' => 'failed', 'error' => $err, 'id' => null];
            }

            // 2. Delete strategy
            if ($action === 'delete') {
                return DB::transaction(function () use ($model, $uuid) {
                    if ($model) {
                        $model->delete();
                        return ['uuid' => $uuid, 'status' => 'deleted', 'error' => null, 'id' => $model->id];
                    }
                    return ['uuid' => $uuid, 'status' => 'deleted', 'error' => null, 'id' => null];
                });
            }

            // 3. Transformation & Mapping
            $transformed = $this->transform($payload, $action);

            // 4. Conflict Resolution
            if ($model && !$this->resolveConflict($model, $transformed)) {
                return [
                    'uuid' => $uuid,
                    'status' => 'conflict_server_won',
                    'error' => null,
                    'id' => $model->id
                ];
            }

            // 5. Database Upsert
            return DB::transaction(function () use ($model, $transformed, $uuid) {
                if ($model && method_exists($model, 'trashed') && $model->trashed()) {
                    $model->restore();
                }

                if ($model) {
                    $model->update($transformed);
                } else {
                    $model = $this->modelClass::create($transformed);
                }

                return [
                    'uuid' => $uuid,
                    'status' => 'applied',
                    'error' => null,
                    'id' => $model->id
                ];
            });

        } catch (\Throwable $throwable) {
            $errMessage = "Exception in sync handler for " . (new $this->modelClass)->getTable() . ": " . $throwable->getMessage();
            Log::error($errMessage, [
                'uuid' => $uuid,
                'payload' => $payload,
                'trace' => $throwable->getTraceAsString()
            ]);
            return ['uuid' => $uuid, 'status' => 'failed', 'error' => $throwable->getMessage(), 'id' => null];
        }
    }
}
