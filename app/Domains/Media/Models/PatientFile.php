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

    // ⚠️ remote_uuid / sha256 / hls_path must stay listed here. They were
    // missing while FileSyncService::processItem() writes them through a plain
    // ->update(), so every value was silently dropped: files came back from a
    // successful upload marked sync_status=synced but with no remote_uuid, the
    // next delta sync failed to match them and inserted a second, empty-path
    // row for the same file — which then rendered as a broken tile in the app.
    protected $fillable = [
        'uuid', 'remote_uuid', 'patient_id', 'uploaded_by_id', 'title', 'desc', 'notes', 'tags', 'type', 'category',
        'date', 'file_name', 'file_path', 'thumbnail_path', 'hls_path', 'upload_status', 'sync_status',
        'client_updated_at', 'mime_type', 'size', 'sha256',
    ];

    protected $casts = [
        'date' => 'date',
        'client_updated_at' => 'datetime',
        'size' => 'integer',
    ];

    protected $appends = ['url', 'thumbnail_url', 'name', 'extension'];

    public function getUrlAttribute()
    {
        // On embedded Laravel (SQLite / NativePHP Mobile App), files are stored
        // locally on the device. Use the local streaming endpoint so the mobile
        // app can access the file without going to the production server.
        if (config('database.default') === 'sqlite') {
            return '/_native/cache/files/' . $this->uuid;
        }
        $baseUrl = rtrim(config('app.url'), '/');
        return $baseUrl . '/api/v1/files/' . $this->uuid;
    }

    public function getThumbnailUrlAttribute()
    {
        // On embedded Laravel (SQLite / NativePHP Mobile App), use local endpoint
        if (config('database.default') === 'sqlite') {
            if ($this->thumbnail_path) {
                return '/_native/cache/files/' . $this->uuid . '/thumbnail';
            }
            if ($this->mime_type && str_starts_with($this->mime_type, 'image/')) {
                return '/_native/cache/files/' . $this->uuid;
            }
            if ($this->mime_type && str_starts_with($this->mime_type, 'video/')) {
                return '/_native/cache/files/' . $this->uuid . '/thumbnail';
            }
            return null;
        }
        $baseUrl = rtrim(config('app.url'), '/');
        if ($this->thumbnail_path) {
            return $baseUrl . '/api/v1/files/' . $this->uuid . '/thumbnail';
        }
        if ($this->mime_type && str_starts_with($this->mime_type, 'image/')) {
            return $this->url;
        }
        if ($this->mime_type && str_starts_with($this->mime_type, 'video/')) {
            return $baseUrl . '/api/v1/files/' . $this->uuid . '/thumbnail';
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
