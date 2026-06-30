<?php

namespace App\Domains\Media\Models;

use App\Domains\Patients\Models\Patient;
use App\Domains\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class PatientFile extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'uuid', 'patient_id', 'uploaded_by_id', 'title', 'desc', 'type', 'category',
        'date', 'file_name', 'file_path', 'hls_path', 'duration', 'width', 'height',
        'thumbnail_path', 'upload_status', 'video_metadata', 'processing_times',
        'client_updated_at', 'mime_type', 'size',
    ];

    protected $casts = [
        'date'             => 'date',
        'video_metadata'   => 'array',
        'processing_times' => 'array',
        'client_updated_at' => 'datetime',
    ];

    protected $appends = ['url', 'thumbnail_url', 'hls_url', 'name'];

    public function getUrlAttribute()
    {
        return url('/api/v1/files/' . $this->uuid);
    }

    public function getHlsUrlAttribute()
    {
        if (!$this->hls_path) return null;
        return url('/api/v1/files/' . $this->uuid . '/hls/playlist.m3u8');
    }

    public function getThumbnailUrlAttribute()
    {
        return $this->thumbnail_path ? url('/api/v1/files/' . $this->uuid . '/thumbnail') : null;
    }

    public function getNameAttribute()
    {
        return $this->file_name;
    }

    protected static function booted()
    {
        static::addGlobalScope(new \App\Domains\Auth\Scopes\DoctorIsolationScope);

        static::creating(function ($model) {
            if (empty($model->uuid)) $model->uuid = (string) \Illuminate\Support\Str::uuid();
        });
    }

    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }
}
