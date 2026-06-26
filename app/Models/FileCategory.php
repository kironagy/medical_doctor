<?php

namespace App\Models;

use App\Models\Concerns\HasSyncIdentity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class FileCategory extends Model
{
    use HasSyncIdentity, SoftDeletes;

    protected $fillable = ['uuid', 'name', 'icon', 'color', 'client_updated_at'];

    protected $casts = [
        'client_updated_at' => 'datetime',
    ];
}
