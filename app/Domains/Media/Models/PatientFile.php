<?php

namespace App\Domains\Media\Models;

use App\Domains\Patients\Models\Patient;
use App\Domains\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
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

    /**
     * Base URL of the real production server, derived from mobile_api_url
     * (".../api/v1/mobile") rather than a hardcoded domain — the same
     * literal-domain pattern already caused one confirmed 401 in
     * UnifiedMediaViewer.vue's old video fallback.
     */
    private function remoteOrigin(): string
    {
        return preg_replace('#/api/v1/mobile/?$#', '', config('app.mobile_api_url'));
    }

    public function getUrlAttribute()
    {
        // A synced file's bytes live on production, not on this device — there
        // is no way to serve them "from the app" for a file the app never
        // stored. Point straight at the real, working URL (confirmed live:
        // GET /api/v1/files/{uuid} on production returns 200 with no auth
        // needed — streamDirect() there has no Gate). Only a file that hasn't
        // synced yet (still local-only, pending upload) has bytes ONLY on this
        // device, so that's the one case that must use the local endpoint.
        if (config('database.default') === 'sqlite') {
            // Exception to the rule above: OfflinePackageService pulls full
            // bytes to disk for an explicit per-patient offline snapshot and
            // marks those rows sync_status='synced' too (same shape as
            // production). Without this check, playback for those files was
            // routed to the remote URL — unreachable while actually offline,
            // which is exactly when this snapshot exists to be used — so
            // videos would play whatever the OS had buffered from the dead
            // request (a second or two) and then stall. Bytes on disk win
            // whenever they're actually present, regardless of sync_status.
            if ($this->file_path && Storage::disk('local')->exists($this->file_path) && Storage::disk('local')->size($this->file_path) > 0) {
                return '/_native/cache/files/' . $this->uuid;
            }
            // remote_uuid ?: uuid — same fallback already used throughout
            // FileAccessController/FileCacheRepository/FileSyncService. Files
            // synced before the $fillable fix (remote_uuid, sha256, hls_path
            // were missing from it) have sync_status=synced but a null
            // remote_uuid, even though the server accepted the client-
            // generated uuid as its own id. Without this fallback those files
            // fell back to the local endpoint and hit the same hydration path
            // Bug #10 just fixed away.
            if ($this->sync_status === 'synced') {
                return $this->remoteOrigin() . '/api/v1/files/' . ($this->remote_uuid ?: $this->uuid);
            }
            return '/_native/cache/files/' . $this->uuid;
        }
        $baseUrl = rtrim(config('app.url'), '/');
        return $baseUrl . '/api/v1/files/' . $this->uuid;
    }

    public function getThumbnailUrlAttribute()
    {
        if (config('database.default') === 'sqlite') {
            // Same local-bytes-first exception as getUrlAttribute() — an
            // offline package's images have no separate thumbnail_path (only
            // the full file), so the image branch below already serves them
            // locally via this same route.
            if ($this->thumbnail_path && Storage::disk('local')->exists($this->thumbnail_path)) {
                return '/_native/cache/files/' . $this->uuid . '/thumbnail';
            }
            if ($this->file_path && Storage::disk('local')->exists($this->file_path) && Storage::disk('local')->size($this->file_path) > 0
                && $this->mime_type && str_starts_with($this->mime_type, 'image/')) {
                return '/_native/cache/files/' . $this->uuid;
            }
            if ($this->sync_status === 'synced') {
                $remoteBase = $this->remoteOrigin() . '/api/v1/files/' . ($this->remote_uuid ?: $this->uuid);
                if ($this->mime_type && str_starts_with($this->mime_type, 'image/')) {
                    return $remoteBase;
                }
                return $remoteBase . '/thumbnail';
            }
            // Not synced yet — bytes only exist on this device.
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

    /**
     * Absolute path of this row's bytes on the `local` disk, or null.
     *
     * file_path is checked first and is the answer for every healthy row.
     * The fallbacks exist because file_path and the name the bytes were
     * actually written under have not always agreed: older upload paths
     * wrote the bytes under the CLIENT's original filename (which, for
     * anything that came off the device, is itself "<deviceUuid>.<ext>")
     * while recording file_path as "<serverFileUuid>.<ext>" — so the row
     * points at a name that was never created and every read of it 404s
     * while the bytes sit in the same directory. 26 production rows are in
     * exactly that state, including the video this was reported on.
     *
     * Probing costs one is_file() per candidate and only ever runs on a
     * path that was already about to 404, so a healthy row is unaffected.
     */
    public function existingAbsolutePath(): ?string
    {
        if (!$this->file_path) {
            return null;
        }

        $disk = Storage::disk('local');

        $abs = $disk->path($this->file_path);
        if (is_file($abs)) {
            return $abs;
        }

        $dir = trim(dirname($this->file_path), '/.');
        $ext = pathinfo($this->file_path, PATHINFO_EXTENSION);

        $candidates = array_filter([
            $this->file_name,
            $ext && $this->uuid ? $this->uuid . '.' . $ext : null,
            $ext && $this->remote_uuid ? $this->remote_uuid . '.' . $ext : null,
        ]);

        foreach (array_unique($candidates) as $candidate) {
            // basename(): file_name is client-supplied and must never be
            // able to walk out of the patient's directory.
            $rel = ($dir !== '' ? $dir . '/' : '') . basename($candidate);
            $abs = $disk->path($rel);
            if (is_file($abs)) {
                return $abs;
            }
        }

        return null;
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
