<?php

namespace App\Http\Controllers\Api;

use App\Contracts\Repositories\FileCacheRepositoryInterface;
use App\Http\Controllers\Controller;
use App\Domains\Media\Models\PatientFile;
use App\Services\OfflineUploadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileAccessController extends Controller
{
    /** Ceiling for the base64 fallback — the whole file is held in memory. */
    private const BASE64_MAX_BYTES = 5 * 1024 * 1024;

    public function __construct(
        private readonly FileCacheRepositoryInterface $cacheRepo,
        private readonly OfflineUploadService $offlineUploadService,
        private readonly \App\Services\Mobile\FileCacheService $fileCacheService,
    ) {}

    /**
     * Absolute path of a file's bytes, wherever they happen to live.
     *
     * Three places hold bytes and each used to be handled by a different
     * method, so a file present in one was reported missing by the others —
     * most visibly thumbnails, which only ever looked at the `local` disk and
     * so returned 204 for every file downloaded from the website (those land
     * in the cache dir with an empty file_path).
     */
    private function resolveAbsolutePath(?PatientFile $file, string $uuid): ?string
    {
        if ($file && $file->file_path) {
            $abs = Storage::disk('local')->path($file->file_path);
            if (is_file($abs)) {
                return $abs;
            }
        }

        $keys = array_values(array_unique(array_filter([
            $uuid,
            $file?->uuid,
            $file?->remote_uuid,
        ])));

        foreach ($keys as $key) {
            $entry = DB::table('file_cache')->where('file_uuid', $key)->first();
            if ($entry) {
                $abs = $this->fileCacheService->resolvePath($entry->local_path);
                if (is_file($abs)) {
                    return $abs;
                }
            }

            $offline = DB::table('offline_files')->where('uuid', $key)->first();
            if ($offline) {
                $abs = $this->offlineUploadService->absolutePath($offline->local_path);
                if (is_file($abs)) {
                    return $abs;
                }
            }
        }

        return null;
    }

    /**
     * Hand the file to the SAPI as a real file instead of echoing bytes by
     * hand. BinaryFileResponse also implements Range/206/416 itself, which is
     * what makes video seeking work.
     */
    private function fileResponse(Request $request, string $absolutePath, ?PatientFile $file, ?string $mimeOverride = null, ?string $nameOverride = null)
    {
        $mime = $mimeOverride
            ?: ($file?->mime_type ?: (mime_content_type($absolutePath) ?: 'application/octet-stream'));
        $name = $nameOverride ?: ($file?->file_name ?: basename($absolutePath));

        $response = response()->file($absolutePath, [
            'Content-Type'        => $mime,
            'Content-Disposition' => 'inline; filename="' . $name . '"',
            'Accept-Ranges'       => 'bytes',
            'Cache-Control'       => 'private, no-transform, max-age=3600',
        ]);

        $response->setAutoEtag();
        $response->setAutoLastModified();

        return $response;
    }
    private function resolveFile(Request $request, string $uuid): PatientFile
    {
        // ⚠️ Always bypass DoctorIsolationScope: on SQLite (mobile, no auth user)
        // the scope filters by uploaded_by_id = null and finds nothing.
        // Support lookup by local UUID OR remote_uuid assigned after sync.
        $query = PatientFile::withoutGlobalScope(
            \App\Domains\Auth\Scopes\DoctorIsolationScope::class
        )->where(function ($q) use ($uuid) {
            $q->where('uuid', $uuid)->orWhere('remote_uuid', $uuid);
        });

        if ($request->hasValidSignature()) {
            return $query->withoutGlobalScopes()->firstOrFail();
        }
        return $query->firstOrFail();
    }

    private function commonHeaders(PatientFile $file): array
    {
        $absolutePath = Storage::disk('local')->path($file->file_path);
        if (!file_exists($absolutePath)) {
            abort(404, 'File not found on disk.');
        }
        $mime = $file->mime_type ?: (mime_content_type($absolutePath) ?: 'application/octet-stream');
        $filemtime = filemtime($absolutePath);
        if ($filemtime === false) {
            $filemtime = time();
        }
        $lastModified = gmdate('D, d M Y H:i:s', $filemtime) . ' GMT';
        $etag = '"' . md5($file->file_path . $file->size . $filemtime) . '"';

        return [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="' . $file->file_name . '"',
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'private, no-transform, max-age=3600',
            'ETag' => $etag,
            'Last-Modified' => $lastModified,
        ];
    }

    private function logStream(string $uuid, string $method, ?string $range, int $status, int $bytes): void
    {
        if (!config('app.debug'))
            return;
        Log::channel('daily')->info('STREAM', [
            'uuid' => $uuid,
            'method' => $method,
            'range' => $range,
            'status' => $status,
            'bytes' => $bytes,
        ]);
    }

    public function generateSignedUrl(Request $request, string $uuid)
    {
        $file = $this->resolveFile($request, $uuid);

        $url = URL::temporarySignedRoute(
            'api.files.stream',
            now()->addHours(6),
            ['uuid' => $file->uuid]
        );

        return response()->json(['url' => $url]);
    }

    public function streamDirect(Request $request, string $uuid)
    {
        $file = $this->resolveFile($request, $uuid);

        $absolutePath = $this->resolveAbsolutePath($file, $uuid);
        if (!$absolutePath) {
            abort(404, 'File not found on disk.');
        }

        return $this->fileResponse($request, $absolutePath, $file);
    }

    public function thumbnailDirect(string $uuid)
    {
        $file = PatientFile::withoutGlobalScope(
            \App\Domains\Auth\Scopes\DoctorIsolationScope::class
        )->where(function ($q) use ($uuid) {
            $q->where('uuid', $uuid)->orWhere('remote_uuid', $uuid);
        })->first();

        // A generated thumbnail always wins when it is actually on disk.
        if ($file && $file->thumbnail_path && Storage::disk('local')->exists($file->thumbnail_path)) {
            return response()->file(Storage::disk('local')->path($file->thumbnail_path), [
                'Content-Type'  => 'image/jpeg',
                'Cache-Control' => 'private, max-age=86400',
            ]);
        }

        // Otherwise fall back to the original bytes, wherever they live —
        // local disk, the download cache, or a pending offline upload. The
        // old version only looked at the `local` disk, so every file synced
        // from the website (empty file_path, bytes in the cache dir) returned
        // 204 and rendered as an empty tile.
        $absolutePath = $this->resolveAbsolutePath($file, $uuid);
        if (!$absolutePath) {
            return response()->noContent();
        }

        $mime = $file?->mime_type ?: (mime_content_type($absolutePath) ?: '');

        if (str_starts_with($mime, 'image/')) {
            $thumb = $this->makeImageThumbnail($absolutePath, $file);
            if ($thumb) {
                return response()->file($thumb, [
                    'Content-Type'  => 'image/jpeg',
                    'Cache-Control' => 'private, max-age=86400',
                ]);
            }

            // Small enough already — serve the original.
            return response()->file($absolutePath, [
                'Content-Type'  => $mime,
                'Cache-Control' => 'private, max-age=86400',
            ]);
        }

        // Video posters are produced on the client at upload time and stored
        // in thumbnail_path (handled above). ffmpeg is never present on
        // Android, so there is nothing to generate here.
        return response()->noContent();
    }

    /**
     * Downscale an image to a <=300px JPEG next to the original and remember
     * it on the model. Returns null when the source is already small enough
     * or GD cannot read it.
     */
    private function makeImageThumbnail(string $inputAbs, ?PatientFile $file): ?string
    {
        try {
            $info = @getimagesize($inputAbs);
            if (!$info) {
                return null;
            }

            [$srcW, $srcH] = $info;
            $maxDim = 300;
            $ratio = min($maxDim / max($srcW, 1), $maxDim / max($srcH, 1));
            if ($ratio >= 1) {
                return null;
            }

            $thumbAbs = $this->fileCacheService->resolvePath(
                'thumbs/' . ($file?->uuid ?: md5($inputAbs)) . '_thumb.jpg'
            );
            if (is_file($thumbAbs) && filesize($thumbAbs) > 512) {
                return $thumbAbs;
            }

            $dir = dirname($thumbAbs);
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                return null;
            }

            $srcImg = @imagecreatefromstring(file_get_contents($inputAbs));
            if (!$srcImg) {
                return null;
            }

            $dstW = (int) round($srcW * $ratio);
            $dstH = (int) round($srcH * $ratio);
            $dstImg = imagecreatetruecolor($dstW, $dstH);
            imagecopyresampled($dstImg, $srcImg, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);
            imagejpeg($dstImg, $thumbAbs, 70);
            imagedestroy($srcImg);
            imagedestroy($dstImg);

            return (is_file($thumbAbs) && filesize($thumbAbs) > 512) ? $thumbAbs : null;
        } catch (\Throwable $e) {
            Log::debug('[FileAccess] thumbnail generation failed: ' . $e->getMessage());
            return null;
        }
    }

    public function status(string $uuid)
    {
        $file = PatientFile::where('uuid', $uuid)->firstOrFail();

        return response()->json([
            'uuid' => $file->uuid,
            'upload_status' => $file->upload_status,
            'type' => $file->type,
            'thumbnail_url' => $file->thumbnail_url,
            'hls_url' => null,
        ]);
    }

    public function update(Request $request, string $uuid)
    {
        $file = PatientFile::where('uuid', $uuid)->firstOrFail();

        if ($request->user() && $request->user()->cannot('update', $file->patient)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'desc' => 'sometimes|string|nullable',
            'category' => 'sometimes|string|nullable',
        ]);

        if (empty($validated)) {
            return response()->json(['message' => 'At least one field must be provided.'], 422);
        }

        $file->update($validated);

        return response()->json($file->fresh());
    }

    public function destroy(Request $request, string $uuid)
    {
        $file = PatientFile::where('uuid', $uuid)->firstOrFail();

        if ($request->user() && $request->user()->cannot('update', $file->patient)) {
            return response()->json(['message' => 'Unauthorized. Only primary doctor or editors can delete files.'], 403);
        }

        // Collect paths to delete
        $pathsToDelete = array_filter([
            $file->file_path,
            $file->thumbnail_path,
            $file->hls_path,
        ]);

        $disk = Storage::disk('local');
        $errors = [];

        foreach ($pathsToDelete as $path) {
            if (empty($path)) continue;
            try {
                // First try delete as file (works for files and may also delete empty dirs depending on driver)
                $deleted = $disk->delete($path);
                if ($deleted) {
                    continue;
                }
                // If not deleted, check if it's a directory and attempt recursive delete
                try {
                    if ($disk->isDirectory($path)) {
                        $disk->deleteDirectory($path);
                    }
                } catch (\Throwable $e2) {
                    // If isDirectory fails because file doesn't exist, ignore
                    $msg2 = strtolower($e2->getMessage());
                    if (!str_contains($msg2, 'file not found') && !str_contains($msg2, 'no such file') && !str_contains($msg2, 'does not exist')) {
                        $errors[] = "Failed to delete directory '{$path}': " . $e2->getMessage();
                    }
                }
            } catch (\Throwable $e) {
                // Main delete threw; check if it's a "not found" error
                $msg = strtolower($e->getMessage());
                if (str_contains($msg, 'file not found') || str_contains($msg, 'no such file') || str_contains($msg, 'does not exist')) {
                    continue;
                }
                $errors[] = "Failed to delete '{$path}': " . $e->getMessage();
                Log::error('File deletion error', [
                    'uuid' => $uuid,
                    'path' => $path,
                    'exception' => $e,
                ]);
            }
        }

        if (!empty($errors)) {
            return response()->json([
                'message' => 'Failed to delete some files',
                'errors' => $errors,
            ], 500);
        }

        try {
            $file->forceDelete();
        } catch (\Throwable $e) {
            Log::error('Failed to force delete PatientFile', ['uuid' => $uuid, 'exception' => $e]);
            return response()->json([
                'message' => 'Failed to delete file record',
                'errors' => [(string) $e->getMessage()],
            ], 500);
        }

        return response()->json(['message' => 'Deleted']);
    }

    // ---------------------------------------------------------------
    //  Phase 6 — Local File Cache
    // ---------------------------------------------------------------

    /**
     * Stream a cached file with Range support for video seeking.
     *
     * Also handles Phase 7 offline pending uploads:
     * if the file uuid is not found in PatientFile, looks in the
     * offline_files table and streams directly from the pending directory.
     *
     * Route: GET /_native/cache/files/{uuid}
     */
    public function streamCached(Request $request, string $uuid)
    {
        // ⚠️ withoutGlobalScope: on SQLite there is no authenticated user, so
        // DoctorIsolationScope would filter by a null doctor and 404 a valid file.
        $file = PatientFile::withoutGlobalScope(
                \App\Domains\Auth\Scopes\DoctorIsolationScope::class
            )
            ->where(function ($q) use ($uuid) {
                $q->where('uuid', $uuid)->orWhere('remote_uuid', $uuid);
            })
            ->first();

        // The device is single-user; enforce the Gate only on the server.
        if ($file && config('database.default') !== 'sqlite') {
            Gate::authorize('view', $file->patient);
        }

        // Already have the bytes somewhere? Serve them and we're done.
        $absolutePath = $this->resolveAbsolutePath($file, $uuid);
        if ($absolutePath) {
            return $this->fileResponse($request, $absolutePath, $file);
        }

        // No bytes locally. Pull them (and the metadata row, if this file was
        // created on the website and never synced here) from the server.
        try {
            $this->cacheRepo->cache($uuid);
        } catch (\Throwable $e) {
            // Log::error (not info/warning): device LOG_LEVEL=error, and this
            // is the one line that explains WHY a file failed to resolve —
            // without it every miss looks identical from the outside.
            Log::error('[streamCached] Remote fetch failed: ' . $e->getMessage(), [
                'uuid'      => $uuid,
                'exception' => get_class($e),
            ]);

            // Never redirect to the remote stream URL here: an <img>/<video>
            // element does not carry the Authorization header, so that
            // redirect could only ever produce a 401.
            abort(503, 'File is not available offline yet. Try again once the device is online.');
        }

        $file = $file ?: PatientFile::withoutGlobalScope(
                \App\Domains\Auth\Scopes\DoctorIsolationScope::class
            )
            ->where(function ($q) use ($uuid) {
                $q->where('uuid', $uuid)->orWhere('remote_uuid', $uuid);
            })
            ->first();

        $absolutePath = $this->resolveAbsolutePath($file, $uuid);
        if (!$absolutePath) {
            abort(404, 'File not found.');
        }

        return $this->fileResponse($request, $absolutePath, $file);
    }

    /**
    /**
     * Same lookup as streamCached(), but returns the bytes base64-encoded in
     * JSON. Kept only as a small-image fallback: encoding costs ~33% more
     * bytes, holds the whole file in PHP memory at once, and cannot serve
     * Range requests, so it must never be the normal media path.
     *
     * Route: GET /_native/cache/files/{uuid}/base64
     */
    public function streamCachedBase64(string $uuid)
    {
        $file = PatientFile::withoutGlobalScope(
                \App\Domains\Auth\Scopes\DoctorIsolationScope::class
            )
            ->where(function ($q) use ($uuid) {
                $q->where('uuid', $uuid)->orWhere('remote_uuid', $uuid);
            })
            ->first();

        $absolutePath = $this->resolveAbsolutePath($file, $uuid);

        if (!$absolutePath) {
            try {
                $this->cacheRepo->cache($uuid);
            } catch (\Throwable $e) {
                // Log::error (not info): device LOG_LEVEL=error strips info out
                // entirely — this is the only line that explains why cache()
                // couldn't resolve the file for Bug #10's E2E trace.
                Log::error('[streamCachedBase64] Remote fetch failed: ' . $e->getMessage(), [
                    'uuid'      => $uuid,
                    'exception' => get_class($e),
                ]);
            }
            $absolutePath = $this->resolveAbsolutePath($file, $uuid);
        }

        if (!$absolutePath) {
            abort(404, 'File not found.');
        }

        if (filesize($absolutePath) > self::BASE64_MAX_BYTES) {
            abort(413, 'File too large for base64; use the streaming endpoint.');
        }

        $mime = $file?->mime_type ?: (mime_content_type($absolutePath) ?: 'application/octet-stream');

        return response()->json([
            'mime' => $mime,
            'data' => base64_encode(file_get_contents($absolutePath)),
        ]);
    }

    /**
     * Cache a file locally for offline viewing.
     *
     * Route: POST /_native/cache/files/{uuid}/cache
     */
    public function cacheFile(Request $request, string $uuid)
    {
        $file = PatientFile::where('uuid', $uuid)->firstOrFail();
        if (config('database.default') !== 'sqlite') {
            Gate::authorize('view', $file->patient);
        }

        $status = $this->cacheRepo->cache($uuid);

        return response()->json($status);
    }

    /**
     * Check if a file is cached and return its status.
     *
     * Also checks the Phase 7 offline_files table for pending uploads.
     *
     * Route: GET /_native/cache/files/{uuid}/status
     */
    public function cacheStatus(Request $request, string $uuid)
    {
        // Check Phase 6 cache first
        $file = PatientFile::where('uuid', $uuid)->first();
        if ($file) {
            if (config('database.default') !== 'sqlite') {
                Gate::authorize('view', $file->patient);
            }
            return response()->json($this->cacheRepo->status($uuid));
        }

        // Check Phase 7 offline pending files
        $offlineFile = DB::table('offline_files')->where('uuid', $uuid)->first();
        if ($offlineFile) {
            $exists = $this->offlineUploadService->fileExists($offlineFile->local_path);
            return response()->json([
                'cached'        => $exists,
                'file_uuid'     => $offlineFile->uuid,
                'file_name'     => $offlineFile->original_name,
                'mime_type'     => $offlineFile->mime_type,
                'size'          => (int) $offlineFile->size,
                'sync_status'   => $offlineFile->sync_status,
                'is_offline'    => true,
            ]);
        }

        return response()->json([
            'cached'    => false,
            'file_uuid' => $uuid,
        ]);
    }

    /**
     * Remove a file from the local cache.
     *
     * Route: DELETE /_native/cache/files/{uuid}
     */
    public function removeCached(Request $request, string $uuid)
    {
        $file = PatientFile::where('uuid', $uuid)->first();
        if ($file && config('database.default') !== 'sqlite') {
            Gate::authorize('view', $file->patient);
        }

        $this->cacheRepo->remove($uuid);

        return response()->json(['message' => 'Removed from cache.']);
    }

    /**
     * Remove all cached files for a patient.
     *
     * Route: DELETE /_native/cache/patient/{patientUuid}
     */
    public function removePatientCached(Request $request, string $patientUuid)
    {
        $patient = \App\Domains\Patients\Models\Patient::where('uuid', $patientUuid)->first();
        if ($patient && config('database.default') !== 'sqlite') {
            Gate::authorize('view', $patient);
        }

        $this->cacheRepo->removePatient($patientUuid);

        return response()->json(['message' => 'Patient cache cleared.']);
    }
}
