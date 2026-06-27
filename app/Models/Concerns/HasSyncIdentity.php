<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

trait HasSyncIdentity
{
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
            if (config('database.default') === 'sqlite') {
                app(\App\Services\OfflineSyncEngine::class)->queue($model->getTable(), 'create', $model->toArray(), $model->uuid);
            }
        });

        static::updated(function ($model): void {
            if (config('database.default') === 'sqlite') {
                app(\App\Services\OfflineSyncEngine::class)->queue($model->getTable(), 'update', $model->toArray(), $model->uuid);
            }
        });

        static::deleted(function ($model): void {
            if (config('database.default') === 'sqlite') {
                app(\App\Services\OfflineSyncEngine::class)->queue($model->getTable(), 'delete', ['id' => $model->id, 'uuid' => $model->uuid], $model->uuid);
            }
        });
    }
}
