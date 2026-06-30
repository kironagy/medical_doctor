<?php

namespace App\Domains\Media\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class FileCategory extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'uuid', 'name', 'icon', 'color', 'client_updated_at'
    ];

    protected $casts = [
        'client_updated_at' => 'datetime',
    ];

    protected static function booted()
    {
        static::creating(function ($model) {
            if (empty($model->uuid)) $model->uuid = (string) Str::uuid();
        });
    }
}
