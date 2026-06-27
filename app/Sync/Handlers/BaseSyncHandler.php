<?php

namespace App\Sync\Handlers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * BaseSyncHandler
 *
 * Provides shared utilities for all entity sync handlers:
 *  - findModelByUuid()
 *  - resolveConflict()
 *  - cleanPayload()
 *  - applyGeneric() — the default create/update/delete path that entity handlers
 *                     may call explicitly. It is NOT invoked automatically.
 *
 * The apply() method in this base class is the default implementation for entities
 * that have no special create/update constraints. Handlers with custom business rules
 * (e.g. UserSyncHandler) MUST override apply() completely and MUST NOT call parent::apply().
 *
 * Subclasses MUST implement:
 *  - validate()
 *  - transform()
 */
abstract class BaseSyncHandler implements SyncHandlerInterface
{
    /**
     * The Eloquent model class managed by this handler.
     *
     * @var class-string<Model>
     */
    protected string $modelClass;

    /**
     * Fields always excluded from upsert payloads (auto-managed by the database).
     */
    protected array $excludedFields = [
        'id',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    // ─── Abstract Contract ─────────────────────────────────────────────────────

    abstract public function validate(array $payload, string $operation, ?Model $model = null): array;
    abstract public function transform(array $payload, string $operation): array;

    // ─── Shared Utilities ─────────────────────────────────────────────────────

    /**
     * Find a model by UUID, including soft-deleted records.
     */
    protected function findModelByUuid(string $uuid): ?Model
    {
        $instance = new $this->modelClass;
        $query    = $this->modelClass::query();

        if (Schema::hasColumn($instance->getTable(), 'deleted_at')) {
            $query->withTrashed();
        }

        return $query->where('uuid', $uuid)->first();
    }

    /**
     * Conflict resolution — last-writer-wins based on client_updated_at.
     * Returns true if the incoming payload should overwrite the current model.
     */
    public function resolveConflict(Model $model, array $payload): bool
    {
        $serverTime      = $model->client_updated_at ?? $model->updated_at;
        $incomingTimeStr = $payload['client_updated_at'] ?? null;

        if (!$incomingTimeStr) {
            return true; // No timestamp on incoming — let it win
        }

        $incomingTime = Carbon::parse($incomingTimeStr);

        // Server model is newer — it wins (incoming loses)
        if ($serverTime && $serverTime->greaterThan($incomingTime)) {
            return false;
        }

        return true;
    }

    /**
     * Remove auto-managed and excluded fields from the payload.
     */
    protected function cleanPayload(array $payload): array
    {
        foreach ($this->excludedFields as $field) {
            unset($payload[$field]);
        }
        return $payload;
    }

    // ─── Generic Apply (default) ───────────────────────────────────────────────

    /**
     * Default apply() — standard create/update/delete for plain entities.
     *
     * Entity handlers with custom business rules MUST override this method
     * completely. Handlers must NEVER call parent::apply() when they have
     * entity-specific constraints (e.g. UserSyncHandler).
     */
    public function apply(string $operation, array $payload, ?string $uuid = null): array
    {
        $action = strtolower($operation);
        $uuid   = $uuid ?: ($payload['uuid'] ?? null);

        if (!$uuid) {
            $err = 'Sync operation failed: missing record UUID.';
            Log::warning($err, ['payload' => $payload, 'handler' => static::class]);
            return ['uuid' => null, 'status' => 'failed', 'error' => $err, 'id' => null];
        }

        try {
            $model = $this->findModelByUuid($uuid);

            // 1. Validate
            $errors = $this->validate($payload, $action, $model);
            if (!empty($errors)) {
                $err = 'Validation failed: ' . json_encode($errors, JSON_UNESCAPED_UNICODE);
                Log::warning("Sync validation failed [{$uuid}] " . (new $this->modelClass)->getTable(), [
                    'uuid'   => $uuid,
                    'errors' => $errors,
                ]);
                return ['uuid' => $uuid, 'status' => 'failed', 'error' => $err, 'id' => null];
            }

            // 2. Delete
            if ($action === 'delete') {
                return DB::transaction(function () use ($model, $uuid) {
                    if ($model) {
                        $model->delete();
                        return ['uuid' => $uuid, 'status' => 'deleted', 'error' => null, 'id' => $model->id];
                    }
                    // Idempotent delete — already gone
                    return ['uuid' => $uuid, 'status' => 'deleted', 'error' => null, 'id' => null];
                });
            }

            // 3. Transform
            $transformed = $this->transform($payload, $action);

            // 4. Conflict resolution
            if ($model && !$this->resolveConflict($model, $transformed)) {
                return [
                    'uuid'   => $uuid,
                    'status' => 'conflict_server_won',
                    'error'  => null,
                    'id'     => $model->id,
                ];
            }

            // 5. Upsert
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
                    'uuid'   => $uuid,
                    'status' => 'applied',
                    'error'  => null,
                    'id'     => $model->id,
                ];
            });

        } catch (\Throwable $throwable) {
            $errMsg = 'Exception in sync handler [' . (new $this->modelClass)->getTable() . "]: " . $throwable->getMessage();
            Log::error($errMsg, [
                'uuid'    => $uuid,
                'payload' => $payload,
                'trace'   => $throwable->getTraceAsString(),
            ]);
            return ['uuid' => $uuid, 'status' => 'failed', 'error' => $throwable->getMessage(), 'id' => null];
        }
    }
}
