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
    }
}
