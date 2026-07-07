<?php

namespace App\Domains\Media\Models;

use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class UploadSession extends Model
{
    protected $table = 'upload_sessions';

    protected $fillable = [
        'uuid',
        'patient_id',
        'user_id',
        'original_name',
        'mime_type',
        'extension',
        'total_size',
        'total_chunks',
        'chunk_size',
        'status',
        'checksum_algorithm',
        'final_checksum',
        'disk',
        'metadata',
        'expires_at',
        'final_path',
        'received_chunk_indexes',
    ];

    protected $casts = [
        'total_size' => 'integer',
        'total_chunks' => 'integer',
        'chunk_size' => 'integer',
        'metadata' => 'json',
        'expires_at' => 'datetime',
        'received_chunk_indexes' => 'array',
    ];

    protected static function booted()
    {
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function chunkDir(): string
    {
        return "tmp/chunks/{$this->uuid}";
    }
}
