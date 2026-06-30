<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Domains\Media\Models\PatientFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileAccessController extends Controller
{
    /**
     * Generate a signed URL for an authorized user to access a file
     */
    public function generateSignedUrl(Request $request, string $uuid)
    {
        // Global scope ensures that if they don't have access to the patient, it throws 404
        $file = PatientFile::where('uuid', $uuid)->firstOrFail();

        $url = URL::temporarySignedRoute(
            'files.stream', now()->addHours(6), ['uuid' => $file->uuid]
        );

        return response()->json(['url' => $url]);
    }

    /**
     * Stream the actual file.
     *
     * Accessible via two paths:
     *  1. Web session (SPA) — user is authenticated, DoctorIsolationScope applies.
     *  2. Temporary signed URL (/api/files/{uuid}/stream?signature=…) — no session,
     *     so we bypass the global scope; the signature itself proves prior authorization.
     *
     * Supports HTTP Range requests (206 Partial Content) for byte-range seeks.
     */
    public function streamDirect(Request $request, string $uuid)
    {
        // Signed-URL requests have no authenticated user, so we must bypass the
        // DoctorIsolationScope (which would return nothing and trigger a 404).
        // The signature is cryptographically tied to the UUID and expires, so
        // skipping the scope here is safe — authorization already happened when
        // the signed URL was generated.
       logger()->info('STREAM DEBUG', [
        'signed' => $request->hasValidSignature(),
        'auth' => auth()->check(),
        'user' => auth()->id(),
        'url' => $request->fullUrl(),
        'headers' => [
            'cookie' => $request->header('cookie'),
            'range' => $request->header('range'),
        ],
    ]);

    $query = $request->hasValidSignature()
        ? PatientFile::withoutGlobalScopes()->where('uuid', $uuid)
        : PatientFile::where('uuid', $uuid);

    $file = $query->firstOrFail();

        $path = $file->file_path;
        if (!Storage::disk('local')->exists($path)) {
            abort(404, 'File not found on disk.');
        }

        $absolutePath = Storage::disk('local')->path($path);
        $fileSize = filesize($absolutePath);
        $mime = mime_content_type($absolutePath) ?: 'application/octet-stream';

        $rangeHeader = $request->header('Range');

        // No Range header -> serve whole file with 200 + disable
        if (!$rangeHeader) {
            return response()->file($absolutePath, [
                'Content-Type' => $mime,
                'Accept-Ranges' => 'bytes',
                'Content-Disposition' => 'inline; filename="' . $file->file_name . '"',
            ]);
        }

        // Parse Range header: "bytes=start-end"
        if (!preg_match('/bytes=(\d*)-(\d*)/', $rangeHeader, $m)) {
            return response('', 416)
                ->header('Content-Range', "bytes */{$fileSize}");
        }

        $start = $m[1] !== '' ? (int) $m[1] : null;
        $end = $m[2] !== '' ? (int) $m[2] : null;

        if ($start === null && $end !== null) {
            // suffix range: last $end bytes
            $start = max(0, $fileSize - $end);
            $end = $fileSize - 1;
        } elseif ($start === null) {
            $start = 0;
            $end = $fileSize - 1;
        }

        if ($end === null || $end >= $fileSize) {
            $end = $fileSize - 1;
        }

        if ($start > $end || $start >= $fileSize) {
            return response('', 416)
                ->header('Content-Range', "bytes */{$fileSize}");
        }

        $length = $end - $start + 1;

        // Use stream + fseek to avoid loading whole file in memory
        $fp = fopen($absolutePath, 'rb');
        if ($start > 0) {
            fseek($fp, $start);
        }

        return new StreamedResponse(function () use ($fp, $length) {
            $remaining = $length;
            $buf = 256 * 1024; // 256KB
            while (!feof($fp) && $remaining > 0) {
                $read = min($buf, $remaining);
                echo fread($fp, $read);
                $remaining -= $read;
                fflush($fp);
            }
            fclose($fp);
        }, 206, [
            'Content-Type' => $mime,
            'Accept-Ranges' => 'bytes',
            'Content-Range' => "bytes {$start}-{$end}/{$fileSize}",
            'Content-Length' => $length,
            'Content-Disposition' => 'inline; filename="' . $file->file_name . '"',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /**
     * Serve a HLS segment or playlist for a video file.
     */
    public function serveHls(Request $request, string $uuid, string $path)
    {
        $file = PatientFile::where('uuid', $uuid)->firstOrFail();

        $base = dirname($file->file_path ?? '');
        // normalize and prevent path traversal
        $rel = $base . '/hls/' . ltrim($path, '/');

        // disallow ..
        if (str_contains($path, '..')) {
            abort(403);
        }

        if (!Storage::disk('local')->exists($rel)) {
            abort(404, 'HLS segment not found.');
        }

        $abs = Storage::disk('local')->path($rel);
        $ext = pathinfo($rel, PATHINFO_EXTENSION);

        $mime = $ext === 'ts'
            ? 'video/mp2t'
            : ($ext === 'm3u8' ? 'application/vnd.apple.mpegurl' : 'application/octet-stream');

        // Segments are immutable -> cache aggressively
        return response()->file($abs, [
            'Content-Type' => $mime,
            'Cache-Control' => 'public, max-age=86400',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    public function thumbnailDirect(string $uuid)
    {
        $file = PatientFile::where('uuid', $uuid)->firstOrFail();

        $path = $file->thumbnail_path;

        // Happy path — thumbnail already exists on disk.
        if ($path && Storage::disk('local')->exists($path)) {
            return response()->file(Storage::disk('local')->path($path), [
                'Content-Type'  => 'image/jpeg',
                'Cache-Control' => 'private, max-age=86400',
            ]);
        }

        // Thumbnail missing — try to generate it on-the-fly (takes ~50ms).
        // This handles: files uploaded before thumbnail generation was added,
        // files whose GenerateThumbnailJob hasn't run yet, and production deploys
        // where the job queue wasn't running at upload time.
        if ($file->file_path && Storage::disk('local')->exists($file->file_path)) {
            $inputAbs = Storage::disk('local')->path($file->file_path);
            $thumbRel  = substr($file->file_path, 0, strrpos($file->file_path, '.')) . '_thumb.jpg';
            $thumbAbs  = Storage::disk('local')->path($thumbRel);

            $process = new \Symfony\Component\Process\Process([
                'ffmpeg', '-y', '-ss', '1', '-i', $inputAbs,
                '-vframes', '1', '-vf', 'scale=-1:300', '-q:v', '5',
                $thumbAbs,
            ]);
            $process->setTimeout(30);
            $process->run();

            if ($process->isSuccessful() && file_exists($thumbAbs) && filesize($thumbAbs) > 512) {
                // Persist so next request is instant.
                $file->update(['thumbnail_path' => $thumbRel]);

                return response()->file($thumbAbs, [
                    'Content-Type'  => 'image/jpeg',
                    'Cache-Control' => 'private, max-age=86400',
                ]);
            }
        }

        // Nothing we can do — return 204 No Content so the browser doesn't
        // show a broken-image icon. The frontend hides the <img> on error anyway.
        return response()->noContent();
    }

    /**
     * Lightweight endpoint to poll a file's processing status (after upload).
     * Used by the global upload manager to transition "uploading" -> "processing" -> "ready".
     */
    public function status(string $uuid)
    {
        $file = PatientFile::where('uuid', $uuid)->firstOrFail();

        return response()->json([
            'uuid'          => $file->uuid,
            'upload_status' => $file->upload_status,
            'type'          => $file->type,
            'thumbnail_url' => $file->thumbnail_url,
            // hls_url is always null (HLS generation removed — not needed for document storage)
            'hls_url'       => null,
        ]);
    }

    public function update(Request $request, string $uuid)
    {
        $file = PatientFile::where('uuid', $uuid)->firstOrFail();

        if ($request->user()->cannot('update', $file->patient)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'desc' => 'sometimes|string|nullable',
            'file_name' => 'sometimes|string|max:255',
            'category' => 'sometimes|string|max:255',
        ]);

        $file->update($validated);

        return response()->json($file);
    }

    public function destroy(Request $request, string $uuid)
    {
        $file = PatientFile::where('uuid', $uuid)->firstOrFail();

        if ($request->user()->cannot('delete', $file->patient)) {
            return response()->json(['message' => 'Unauthorized. Only primary doctor can delete files.'], 403);
        }

        $file->delete(); // Soft delete
        return response()->json(['message' => 'Deleted']);
    }
}
