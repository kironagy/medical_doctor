<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SyncQueueItem extends Model
{
    protected $table = 'sync_queue';

    protected $fillable = [
        'uuid',
        'table_name',
        'record_uuid',
        'operation',
        'payload',
        'retry_count',
        'status',
        'last_error',
        'available_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'available_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (SyncQueueItem $item): void {
            $item->uuid ??= (string) Str::uuid();
            $item->available_at ??= now();
        });
    }
}
