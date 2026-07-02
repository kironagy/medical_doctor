<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Domains\Media\Models\PatientFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaSyncController extends Controller
{
    public function metadata(Request $request, string $fileUuid): JsonResponse
    {
        $file = PatientFile::where('uuid', $fileUuid)->firstOrFail();
        $this->authorize('view', $file->patient);

        $absolutePath = Storage::disk('local')->path($file->file_path);

        return response()->json([
            'uuid' => $file->uuid,
            'file_name' => $file->file_name,
            'mime_type' => $file->mime_type,
            'size' => $file->size,
            'extension' => $file->extension,
            'category' => $file->category,
            'title' => $file->title,
            'desc' => $file->desc,
            'notes' => $file->notes,
            'tags' => $file->tags,
            'date' => $file->date,
            'created_at' => $file->created_at,
            'updated_at' => $file->updated_at,
            'thumbnail_url' => $file->thumbnail_url,
            'has_thumbnail' => $file->thumbnail_path && Storage::disk('local')->exists($file->thumbnail_path),
            'exists_on_disk' => Storage::disk('local')->exists($file->file_path),
            'size_on_disk' => $absolutePath ? filesize($absolutePath) : 0,
        ]);
    }

    public function download(Request $request, string $fileUuid): StreamedResponse|JsonResponse
    {
        $file = PatientFile::where('uuid', $fileUuid)->firstOrFail();
        $this->authorize('view', $file->patient);

        if (!Storage::disk('local')->exists($file->file_path)) {
            return response()->json(['message' => 'File not found on disk.'], 404);
        }

        $absolutePath = Storage::disk('local')->path($file->file_path);
        $fileSize = filesize($absolutePath);

        $range = $request->header('Range');

        if ($range) {
            preg_match('/bytes=(\d*)-(\d*)/', $range, $matches);
            $start = $matches[1] !== '' ? (int) $matches[1] : 0;
            $end = $matches[2] !== '' ? (int) $matches[2] : $fileSize - 1;
            if ($end >= $fileSize) $end = $fileSize - 1;

            $length = $end - $start + 1;

            return response()->stream(function () use ($absolutePath, $start, $length) {
                $fp = fopen($absolutePath, 'rb');
                fseek($fp, $start);
                echo fread($fp, $length);
                fclose($fp);
            }, 206, [
                'Content-Type' => $file->mime_type ?? 'application/octet-stream',
                'Content-Length' => $length,
                'Content-Range' => "bytes {$start}-{$end}/{$fileSize}",
                'Accept-Ranges' => 'bytes',
                'Content-Disposition' => 'attachment; filename="' . $file->file_name . '"',
                'Cache-Control' => 'private, no-transform',
            ]);
        }

        return response()->stream(function () use ($absolutePath) {
            $fp = fopen($absolutePath, 'rb');
            while (!feof($fp)) {
                echo fread($fp, 1024 * 1024);
                fflush($fp);
            }
            fclose($fp);
        }, 200, [
            'Content-Type' => $file->mime_type ?? 'application/octet-stream',
            'Content-Length' => $fileSize,
            'Content-Disposition' => 'attachment; filename="' . $file->file_name . '"',
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'private, no-transform',
        ]);
    }

    public function thumbnail(Request $request, string $fileUuid): StreamedResponse|JsonResponse
    {
        $file = PatientFile::where('uuid', $fileUuid)->firstOrFail();
        $this->authorize('view', $file->patient);

        if ($file->thumbnail_path && Storage::disk('local')->exists($file->thumbnail_path)) {
            $thumbPath = Storage::disk('local')->path($file->thumbnail_path);
            return response()->stream(function () use ($thumbPath) {
                readfile($thumbPath);
            }, 200, [
                'Content-Type' => 'image/jpeg',
                'Content-Length' => filesize($thumbPath),
                'Cache-Control' => 'private, max-age=86400',
            ]);
        }

        return response()->json(null, 204);
    }
}
