<?php

namespace App\Models;

use App\Models\Concerns\HasSyncIdentity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PatientFile extends Model
{
    use HasFactory, HasSyncIdentity, SoftDeletes;

    protected $fillable = ['uuid', 'patient_id', 'uploaded_by_id', 'title', 'desc', 'type', 'category', 'date', 'file_name', 'file_path', 'mime_type', 'size', 'thumbnail_path', 'upload_status', 'data', 'client_updated_at', 'duration', 'resolution', 'processing_progress', 'processing_stage'];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'client_updated_at' => 'datetime',
        'size' => 'integer',
    ];

    protected $appends = ['url', 'thumbnail_url'];

    protected static function booted()
    {
        static::deleting(function (PatientFile $file) {
            $file->deletePhysicalFiles();
        });
    }

    public function deletePhysicalFiles()
    {
        if ($this->uuid) {
            $uuidDir = storage_path('app/public/patient_files/' . $this->uuid);
            if (file_exists($uuidDir) && is_dir($uuidDir)) {
                self::deleteDir($uuidDir);
            }
        }

        if ($this->file_path) {
            $pathStr = $this->file_path;
            if (str_starts_with($pathStr, '/storage/')) {
                $pathStr = substr($pathStr, 9);
            }
            $fullPath = storage_path('app/public/' . $pathStr);
            if (file_exists($fullPath) && is_file($fullPath)) {
                unlink($fullPath);
            }
        }

        if ($this->thumbnail_path) {
            $thumbStr = $this->thumbnail_path;
            if (str_starts_with($thumbStr, '/storage/')) {
                $thumbStr = substr($thumbStr, 9);
            }
            $fullThumbPath = storage_path('app/public/' . $thumbStr);
            if (file_exists($fullThumbPath) && is_file($fullThumbPath)) {
                unlink($fullThumbPath);
            }
        }
    }

    private static function deleteDir(string $dirPath)
    {
        if (!is_dir($dirPath)) return;
        $files = array_diff(scandir($dirPath), ['.', '..']);
        foreach ($files as $file) {
            (is_dir("$dirPath/$file")) ? self::deleteDir("$dirPath/$file") : unlink("$dirPath/$file");
        }
        rmdir($dirPath);
    }

    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }

    public function getUrlAttribute()
    {
        $baseUrl = rtrim(config('app.url'), '/');
        return $baseUrl . '/api/v1/files/' . $this->uuid;
    }

    public function getThumbnailUrlAttribute()
    {
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
}
