<?php

namespace App\Domains\Sync\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SyncQueue extends Model
{
    protected $table = 'sync_queue';

    protected $fillable = [
        'uuid',
        'entity_type',
        'entity_uuid',
        'operation',
        'payload_version',
        'payload',
        'status',
        'retry_count',
        'last_error',
        'last_attempt_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'payload_version' => 'integer',
        'retry_count' => 'integer',
        'last_attempt_at' => 'datetime',
    ];

    protected static function booted()
    {
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }
}
