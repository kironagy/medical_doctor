<?php

namespace App\Domains\Mobile\Models;

use App\Domains\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MobileDevice extends Model
{
    protected $table = 'mobile_devices';

    protected $fillable = [
        'uuid',
        'user_id',
        'device_id',
        'platform',
        'push_token',
        'last_sync_at',
    ];

    protected $casts = [
        'last_sync_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (MobileDevice $device) {
            if (empty($device->uuid)) {
                $device->uuid = (string) Str::uuid();
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
