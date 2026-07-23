<?php

namespace App\Services\Mobile;

use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileCacheService
{
    private const CACHE_DIR = 'app/cache';

    /**
     * Stream a file from disk with Range (partial content) support.
     *
     * Uses StreamedResponse with a 1 MB buffer — never loads the
     * entire file into memory. Large videos can be seeked without
     * memory spikes.
     */
    public function streamFile(
        string $absolutePath,
        string $mimeType,
        string $fileName,
        ?string $rangeHeader = null,
        bool $isHead = false
    ): StreamedResponse {
        $fileSize = filesize($absolutePath);

        $headers = [
            'Content-Type'        => $mimeType,
            'Content-Disposition' => 'inline; filename="' . $fileName . '"',
            'Accept-Ranges'       => 'bytes',
            'Cache-Control'       => 'private, no-transform, max-age=3600',
        ];

        if (!$rangeHeader) {
            $headers['Content-Length'] = (string) $fileSize;

            if ($isHead) {
                return new StreamedResponse(function () {}, 200, $headers);
            }

            $fp = fopen($absolutePath, 'rb');

            return new StreamedResponse(function () use ($fp) {
                $buf = 1024 * 1024;
                while (!feof($fp)) {
                    echo fread($fp, $buf);
                    fflush($fp);
                }
                fclose($fp);
            }, 200, $headers);
        }

        // Parse Range header for partial content (video seeking)
        if (!preg_match('/bytes=(\d*)-(\d*)/', $rangeHeader, $m)) {
            return new StreamedResponse(function () {}, 416, [
                'Content-Range' => "bytes */{$fileSize}",
            ]);
        }

        $start = $m[1] !== '' ? (int) $m[1] : null;
        $end   = $m[2] !== '' ? (int) $m[2] : null;

        if ($start === null && $end !== null) {
            $start = max(0, $fileSize - $end);
            $end   = $fileSize - 1;
        } elseif ($start === null) {
            $start = 0;
            $end   = $fileSize - 1;
        }

        if ($end === null || $end >= $fileSize) {
            $end = $fileSize - 1;
        }

        if ($start > $end || $start >= $fileSize) {
            return new StreamedResponse(function () {}, 416, [
                'Content-Range' => "bytes */{$fileSize}",
            ]);
        }

        $length = $end - $start + 1;
        unset($headers['Content-Length']);
        $headers['Content-Range'] = "bytes {$start}-{$end}/{$fileSize}";
        $headers['Content-Length'] = (string) $length;

        if ($isHead) {
            return new StreamedResponse(function () {}, 206, $headers);
        }

        $fp = fopen($absolutePath, 'rb');
        if ($start > 0) {
            fseek($fp, $start);
        }

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

    public function deleteFile(string $path): bool
    {
        if (File::exists($path)) {
            return File::delete($path);
        }
        return true;
    }

    public function fileExists(string $path): bool
    {
        return File::exists($path);
    }

    public function clearDirectory(): void
    {
        $dir = $this->filesDirectory();
        if (File::isDirectory($dir)) {
            File::cleanDirectory($dir);
        }
    }

    public function filesDirectory(): string
    {
        $dir = $this->cacheDirectory() . '/files';
        File::ensureDirectoryExists($dir);
        return $dir;
    }

    public function cacheDirectory(): string
    {
        return storage_path(self::CACHE_DIR);
    }

    /**
     * Convert a relative path (e.g. "files/{uuid}.pdf") to an absolute path.
     */
    public function resolvePath(string $relativePath): string
    {
        return $this->cacheDirectory() . '/' . ltrim($relativePath, '/');
    }

    /**
     * Build the destination path for a cached file.
     */
    public function buildDestination(string $fileUuid, string $extension): string
    {
        return $this->filesDirectory() . '/' . $fileUuid . '.' . $extension;
    }
}
