<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SyncQueueItem extends Model
{
    use HasFactory;

    protected $table = 'sync_queue';

    protected $fillable = [
        'uuid',
        'entity',
        'table_name',
        'record_uuid',
        'operation',
        'payload',
        'priority',
        'retry_count',
        'status',
        'last_error',
        'last_attempt_at',
        'available_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'last_attempt_at' => 'datetime',
        'available_at' => 'datetime',
    ];
}
