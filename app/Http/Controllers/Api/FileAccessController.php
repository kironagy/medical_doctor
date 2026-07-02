<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Domains\Media\Models\PatientFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileAccessController extends Controller
{
    private function resolveFile(Request $request, string $uuid): PatientFile
    {
        if ($request->hasValidSignature()) {
            return PatientFile::withoutGlobalScopes()->where('uuid', $uuid)->firstOrFail();
        }
        return PatientFile::where('uuid', $uuid)->firstOrFail();
    }

    private function commonHeaders(PatientFile $file): array
    {
        $absolutePath = Storage::disk('local')->path($file->file_path);
        $mime = mime_content_type($absolutePath) ?: 'application/octet-stream';
        $lastModified = gmdate('D, d M Y H:i:s', filemtime($absolutePath)) . ' GMT';
        $etag = '"' . md5($file->file_path . $file->size . filemtime($absolutePath)) . '"';

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
        if (!config('app.debug')) return;
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
            'api.files.stream', now()->addHours(6), ['uuid' => $file->uuid]
        );

        return response()->json(['url' => $url]);
    }

    public function streamDirect(Request $request, string $uuid)
    {
        $file = $this->resolveFile($request, $uuid);

        $path = $file->file_path;
        if (!Storage::disk('local')->exists($path)) {
            abort(404, 'File not found on disk.');
        }

        $absolutePath = Storage::disk('local')->path($path);
        $fileSize = filesize($absolutePath);
        $headers = $this->commonHeaders($file);
        $headers['Content-Length'] = (string) $fileSize;

        // HEAD request — return metadata only
        if ($request->isMethod('HEAD')) {
            return response('', 200, $headers);
        }

        $rangeHeader = $request->header('Range');

        // No Range header — stream whole file with 200
        if (!$rangeHeader) {
            @ini_set('output_handler', '');
            @ini_set('zlib.output_compression', 0);
            @ini_set('max_execution_time', 3600);
            while (ob_get_level()) ob_end_clean();

            $fp = fopen($absolutePath, 'rb');
            $this->logStream($uuid, 'GET', null, 200, $fileSize);
            return new StreamedResponse(function () use ($fp) {
                $buf = 1024 * 1024;
                while (!feof($fp)) {
                    echo fread($fp, $buf);
                    fflush($fp);
                }
                fclose($fp);
            }, 200, $headers);
        }

        // Parse Range header: "bytes=start-end"
        if (!preg_match('/bytes=(\d*)-(\d*)/', $rangeHeader, $m)) {
            return response('', 416)
                ->header('Content-Range', "bytes */{$fileSize}");
        }

        $start = $m[1] !== '' ? (int) $m[1] : null;
        $end = $m[2] !== '' ? (int) $m[2] : null;

        if ($start === null && $end !== null) {
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

        unset($headers['Content-Length']);
        $headers['Content-Range'] = "bytes {$start}-{$end}/{$fileSize}";
        $headers['Content-Length'] = (string) $length;

        @ini_set('output_handler', '');
        @ini_set('zlib.output_compression', 0);
        @ini_set('max_execution_time', 3600);
        while (ob_get_level()) ob_end_clean();

        $fp = fopen($absolutePath, 'rb');
        if ($start > 0) {
            fseek($fp, $start);
        }

        $this->logStream($uuid, 'GET', $rangeHeader, 206, $length);

        return new StreamedResponse(function () use ($fp, $length) {
            $remaining = $length;
            $buf = 1024 * 1024;
            while (!feof($fp) && $remaining > 0) {
                $read = min($buf, $remaining);
                echo fread($fp, $read);
                $remaining -= $read;
                fflush($fp);
            }
            fclose($fp);
        }, 206, $headers);
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

            // On-the-fly thumbnail for images using GD
            if ($file->mime_type && str_starts_with($file->mime_type, 'image/')) {
                try {
                    $imgInfo = @getimagesize($inputAbs);
                    if ($imgInfo) {
                        $srcW = $imgInfo[0];
                        $srcH = $imgInfo[1];
                        $maxDim = 300;
                        $ratio = min($maxDim / max($srcW, 1), $maxDim / max($srcH, 1));
                        if ($ratio < 1) {
                            $dstW = (int)round($srcW * $ratio);
                            $dstH = (int)round($srcH * $ratio);
                            $srcImg = @imagecreatefromstring(file_get_contents($inputAbs));
                            if ($srcImg) {
                                $dstImg = imagecreatetruecolor($dstW, $dstH);
                                imagecopyresampled($dstImg, $srcImg, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);
                                imagejpeg($dstImg, $thumbAbs, 70);
                                imagedestroy($srcImg);
                                imagedestroy($dstImg);
                                if (file_exists($thumbAbs) && filesize($thumbAbs) > 512) {
                                    $file->update(['thumbnail_path' => $thumbRel]);
                                    return response()->file($thumbAbs, [
                                        'Content-Type' => 'image/jpeg',
                                        'Cache-Control' => 'private, max-age=86400',
                                    ]);
                                }
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    // GD not available or unsupported format — fall through to original
                }
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
            'notes' => 'sometimes|string|nullable',
            'tags' => 'sometimes|string|nullable',
            'file_name' => 'sometimes|string|max:255',
            'category' => 'sometimes|string|max:255',
            'date' => 'sometimes|date|nullable',
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
