<?php

namespace App\Domains\Offline\Models;

use Illuminate\Database\Eloquent\Model;

class OfflinePackage extends Model
{
    public const STATUS_DOWNLOADING = 'downloading';
    public const STATUS_VERIFYING = 'verifying';
    public const STATUS_READY = 'ready';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'patient_uuid',
        'owner_user_id',
        'status',
        'downloaded_at',
        'last_refreshed_at',
        'error',
    ];

    protected $casts = [
        'downloaded_at' => 'datetime',
        'last_refreshed_at' => 'datetime',
    ];
}
