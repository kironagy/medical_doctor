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
        'uuid', 'patient_id', 'uploaded_by_id', 'title', 'desc', 'notes', 'tags', 'type', 'category',
        'date', 'file_name', 'file_path', 'thumbnail_path', 'upload_status',
        'client_updated_at', 'mime_type', 'size',
    ];

    protected $casts = [
        'date' => 'date',
        'client_updated_at' => 'datetime',
        'size' => 'integer',
    ];

    protected $appends = ['url', 'thumbnail_url', 'name', 'extension'];

    public function getUrlAttribute()
    {
        // Use request-relative URL so it works in both mobile and web contexts
        return url('/api/v1/files/' . $this->uuid);
    }

    public function getThumbnailUrlAttribute()
    {
        if ($this->thumbnail_path) {
            return url('/api/v1/files/' . $this->uuid . '/thumbnail');
        }
        if ($this->mime_type && str_starts_with($this->mime_type, 'image/')) {
            return $this->url;
        }
        if ($this->mime_type && str_starts_with($this->mime_type, 'video/')) {
            return url('/api/v1/files/' . $this->uuid . '/thumbnail');
        }
        return null;
    }

    public function getNameAttribute()
    {
        return $this->file_name;
    }

    public function getExtensionAttribute()
    {
        $parts = $this->file_name ? explode('.', $this->file_name) : [];
        return count($parts) > 1 ? strtolower(end($parts)) : null;
    }

    protected static function booted()
    {
        static::addGlobalScope(new \App\Domains\Auth\Scopes\DoctorIsolationScope);

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

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }
}
