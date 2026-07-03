<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PendingOperation extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'entity_type',
        'action',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];
}
