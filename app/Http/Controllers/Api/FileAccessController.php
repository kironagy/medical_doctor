<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PatientFile;
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
        if (!file_exists($absolutePath)) {
            abort(404, 'File not found on disk.');
        }
        $mime = mime_content_type($absolutePath) ?: 'application/octet-stream';
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

        $path = $file->file_path;
        if (!Storage::disk('local')->exists($path)) {
            abort(404, 'File not found on disk.');
        }

        $absolutePath = Storage::disk('local')->path($path);
        $fileSize = filesize($absolutePath);
        $headers = $this->commonHeaders($file);
        $headers['Content-Length'] = (string) $fileSize;

        if ($request->isMethod('HEAD')) {
            return response('', 200, $headers);
        }

        $rangeHeader = $request->header('Range');

        if (!$rangeHeader) {
            @ini_set('output_handler', '');
            @ini_set('zlib.output_compression', 0);
            @ini_set('max_execution_time', 3600);
            while (ob_get_level())
                ob_end_clean();

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
        while (ob_get_level())
            ob_end_clean();

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
        if (empty($path) || !Storage::disk('local')->exists($path)) {
            return response()->noContent();
        }

        $path = $file->thumbnail_path;
        if ($path && Storage::disk('local')->exists($path)) {
            return response()->file(Storage::disk('local')->path($path), [
                'Content-Type' => 'image/jpeg',
                'Cache-Control' => 'private, max-age=86400',
            ]);
        }

        if ($file->file_path && Storage::disk('local')->exists($file->file_path)) {
            $inputAbs = Storage::disk('local')->path($file->file_path);
            $thumbRel = substr($file->file_path, 0, strrpos($file->file_path, '.')) . '_thumb.jpg';
            $thumbAbs = Storage::disk('local')->path($thumbRel);

            $ffmpegExists = false;
            if (function_exists('exec')) {
                try {
                    $whichCmd = DIRECTORY_SEPARATOR === '\\' ? 'where ffmpeg' : 'which ffmpeg';
                    @exec($whichCmd, $output, $returnVar);
                    $ffmpegExists = ($returnVar === 0);
                } catch (\Throwable $e) {}
            }

            if ($ffmpegExists) {
                $process = new \Symfony\Component\Process\Process([
                    'ffmpeg',
                    '-y',
                    '-ss',
                    '1',
                    '-i',
                    $inputAbs,
                    '-vframes',
                    '1',
                    '-vf',
                    'scale=-1:300',
                    '-q:v',
                    '5',
                    $thumbAbs,
                ]);
                $process->setTimeout(10);
                try {
                    $process->run();
                    if ($process->isSuccessful() && file_exists($thumbAbs) && filesize($thumbAbs) > 512) {
                        $file->update(['thumbnail_path' => $thumbRel]);
                        return response()->file($thumbAbs, [
                            'Content-Type' => 'image/jpeg',
                            'Cache-Control' => 'private, max-age=86400',
                        ]);
                    }
                } catch (\Throwable $e) {
                    Log::warning('[FileAccess] ffmpeg thumbnailing failed: ' . $e->getMessage());
                }
            }

            if ($file->mime_type && str_starts_with($file->mime_type, 'image/')) {
                try {
                    $imgInfo = @getimagesize($inputAbs);
                    if ($imgInfo) {
                        $srcW = $imgInfo[0];
                        $srcH = $imgInfo[1];
                        $maxDim = 300;
                        $ratio = min($maxDim / max($srcW, 1), $maxDim / max($srcH, 1));
                        if ($ratio < 1) {
                            $dstW = (int) round($srcW * $ratio);
                            $dstH = (int) round($srcH * $ratio);
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
                        } else {
                            return response()->file($inputAbs, [
                                'Content-Type' => $file->mime_type,
                                'Cache-Control' => 'private, max-age=86400',
                            ]);
                        }
                    }
                } catch (\Throwable $e) {}

                return response()->file($inputAbs, [
                    'Content-Type' => $file->mime_type,
                    'Cache-Control' => 'private, max-age=86400',
                ]);
            }
        }

        return response()->noContent();
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

        if ($request->user()->cannot('update', $file->patient)) {
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

        if ($request->user()->cannot('delete', $file->patient)) {
            return response()->json(['message' => 'Unauthorized. Only primary doctor can delete files.'], 403);
        }

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
                $deleted = $disk->delete($path);
                if ($deleted) {
                    continue;
                }
                try {
                    if ($disk->isDirectory($path)) {
                        $disk->deleteDirectory($path);
                    }
                } catch (\Throwable $e2) {
                    $msg2 = strtolower($e2->getMessage());
                    if (!str_contains($msg2, 'file not found') && !str_contains($msg2, 'no such file') && !str_contains($msg2, 'does not exist')) {
                        $errors[] = "Failed to delete directory '{$path}': " . $e2->getMessage();
                    }
                }
            } catch (\Throwable $e) {
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
            $file->delete();
        } catch (\Throwable $e) {
            Log::error('Failed to soft delete PatientFile', ['uuid' => $uuid, 'exception' => $e]);
            return response()->json([
                'message' => 'Failed to delete file record',
                'errors' => [(string) $e->getMessage()],
            ], 500);
        }

        return response()->json(['message' => 'Deleted']);
    }
}
