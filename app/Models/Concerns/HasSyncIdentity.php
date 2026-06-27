<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

trait HasSyncIdentity
{
    /**
     * When true, model events (created/updated/deleted) will NOT enqueue items
     * into the offline sync queue. This flag is set by OfflineSyncEngine while
     * it applies remote changes locally to prevent duplicating pull operations
     * back into the upload queue.
     */
    public static bool $applyingRemoteChanges = false;

    protected static function bootHasSyncIdentity(): void
    {
        static::creating(function ($model): void {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }

            if (empty($model->client_updated_at)) {
                $model->client_updated_at = now();
            }
        });

        static::saving(function ($model): void {
            if (empty($model->client_updated_at)) {
                $model->client_updated_at = now();
            }
        });

        static::created(function ($model): void {
            // Do not re-queue records that were downloaded from the server
            if (static::$applyingRemoteChanges) {
                return;
            }

            if (config('database.default') === 'sqlite') {
                $payload = $model->toArray();
                if (($model->getTable() === 'patient_visits' || $model->getTable() === 'patient_files') && $model->patient) {
                    $payload['patient_uuid'] = $model->patient->uuid;
                }
                app(\App\Services\OfflineSyncEngine::class)->queue($model->getTable(), 'create', $payload, $model->uuid);
            }
        });

        static::updated(function ($model): void {
            // Do not re-queue records that were downloaded from the server
            if (static::$applyingRemoteChanges) {
                return;
            }

            if (config('database.default') === 'sqlite') {
                $payload = $model->toArray();
                if (($model->getTable() === 'patient_visits' || $model->getTable() === 'patient_files') && $model->patient) {
                    $payload['patient_uuid'] = $model->patient->uuid;
                }
                app(\App\Services\OfflineSyncEngine::class)->queue($model->getTable(), 'update', $payload, $model->uuid);
            }
        });

        static::deleted(function ($model): void {
            // Do not re-queue records that were downloaded from the server
            if (static::$applyingRemoteChanges) {
                return;
            }

            if (config('database.default') === 'sqlite') {
                $payload = ['id' => $model->id, 'uuid' => $model->uuid];
                if ($model->getTable() === 'patient_visits' || $model->getTable() === 'patient_files') {
                    if ($model->patient) {
                        $payload['patient_uuid'] = $model->patient->uuid;
                    }
                }
                app(\App\Services\OfflineSyncEngine::class)->queue($model->getTable(), 'delete', $payload, $model->uuid);
            }
        });
    }
}
