<?php

namespace App\Domains\Media\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use App\Domains\Media\Models\PatientFile;
use App\Domains\Patients\Models\Patient;
use Exception;

class UploadService
{
    /**
     * Chunk merge buffer size (4 MiB). Larger buffer drastically reduces
     * syscall count when concatenating many chunks.
     */
    private const MERGE_BUFFER = 4 * 1024 * 1024;

    /**
     * Track session directories already ensured to exist within this request,
     * avoiding repeated stat() calls for every chunk in a session.
     */
    private array $ensuredDirs = [];

    public function storeChunk(string $sessionId, UploadedFile $chunk, int $chunkIndex): void
    {
        $start = microtime(true);
        $tmpDir = "tmp/uploads/{$sessionId}";

        $this->ensureDir($tmpDir);

        // storeAs() moves the temp_uploaded_file directly (rename) — O(1) on the same fs.
        $chunk->storeAs($tmpDir, "chunk_{$chunkIndex}", 'local');

        Log::channel('upload')->debug('chunk stored', [
            'session' => $sessionId,
            'index' => $chunkIndex,
            'bytes' => $chunk->getSize(),
            'ms' => round((microtime(true) - $start) * 1000, 2),
        ]);
    }

    /**
     * Returns the set of chunk indexes already received for a session.
     * Enables the client to resume an interrupted upload without re-sending chunks.
     */
    public function receivedChunks(string $sessionId): array
    {
        $tmpDir = "tmp/uploads/{$sessionId}";
        $disk = Storage::disk('local');

        if (!$disk->exists($tmpDir)) {
            return [];
        }

        $indexes = [];
        foreach ($disk->files($tmpDir) as $file) {
            if (preg_match('/chunk_(\d+)$/', $file, $m)) {
                $indexes[] = (int) $m[1];
            }
        }

        sort($indexes, SORT_NUMERIC);
        return $indexes;
    }

    public function mergeChunks(string $sessionId, int $totalChunks, Patient $patient, array $fileMetadata, int $uploaderId): PatientFile
    {
        $t0 = microtime(true);
        $tmpDir = "tmp/uploads/{$sessionId}";
        $disk = Storage::disk('local');
        $extension = $fileMetadata['extension'] ?? 'tmp';

        $fileUuid = (string) Str::uuid();
        $finalRelPath = "patients/{$patient->uuid}/{$fileUuid}.{$extension}";
        $patientDir = "patients/{$patient->uuid}";
        $this->ensureDir($patientDir);

        $finalAbsPath = $disk->path($finalRelPath);

        // Single-pass merge + incremental SHA-256:
        // avoids reading the whole file again from disk just to hash it.
        $final = fopen($finalAbsPath, 'wb');
        if (!$final) {
            throw new Exception("Unable to open final file for writing: {$finalAbsPath}");
        }

        $hashCtx = hash_init('sha256');
        $missing = [];

        for ($i = 0; $i < $totalChunks; $i++) {
            $chunkRel = "{$tmpDir}/chunk_{$i}";
            $chunkAbs = $disk->path($chunkRel);

            if (!file_exists($chunkAbs)) {
                $missing[] = $i;
                continue;
            }

            $cf = fopen($chunkAbs, 'rb');
            if (!$cf) {
                $missing[] = $i;
                continue;
            }

            while (!feof($cf)) {
                $buf = fread($cf, self::MERGE_BUFFER);
                if ($buf === false || $buf === '') {
                    break;
                }
                fwrite($final, $buf);
                hash_update($hashCtx, $buf);
            }

            fclose($cf);
            @unlink($chunkAbs);
        }

        fclose($final);

        if (count($missing) > 0) {
            @unlink($finalAbsPath);
            throw new Exception("Missing chunks for session {$sessionId}: " . implode(',', $missing));
        }

        $hash = hash_final($hashCtx);
        $diskReclaim = microtime(true);
        $disk->deleteDirectory($tmpDir);

        $size = filesize($finalAbsPath) ?: 0;
        $mimeType = $this->resolveMimeType($finalAbsPath, $fileMetadata);

        $type = 'document';
        if (str_starts_with($mimeType, 'image/')) $type = 'image';
        elseif (str_starts_with($mimeType, 'video/')) $type = 'video';
        elseif (str_starts_with($mimeType, 'audio/')) $type = 'audio';
        elseif ($mimeType === 'application/pdf') $type = 'pdf';
        elseif (str_starts_with($mimeType, 'text/')) $type = 'text';

        $patientFile = PatientFile::create([
            'uuid' => $fileUuid,
            'patient_id' => $patient->id,
            'uploaded_by_id' => $uploaderId,
            'title' => $fileMetadata['title'] ?? 'Untitled',
            'desc' => $fileMetadata['desc'] ?? null,
            'type' => $type,
            'mime_type' => $mimeType,
            'size' => $size,
            'category' => $fileMetadata['category'] ?? null,
            'date' => $fileMetadata['date'] ?? now(),
            'file_name' => $fileMetadata['original_name'] ?? "{$fileUuid}.{$extension}",
            'file_path' => $finalRelPath,
            'upload_status' => 'queued',
            'video_metadata' => ['hash' => $hash],
        ]);

        Log::channel('upload')->info('merge complete', [
            'session' => $sessionId,
            'file_uuid' => $fileUuid,
            'total_chunks' => $totalChunks,
            'size' => $size,
            'mime' => $mimeType,
            'merge_ms' => round((microtime(true) - $t0) * 1000, 2),
            'reclaim_ms' => round((microtime(true) - $diskReclaim) * 1000, 2),
            'hash' => $hash,
        ]);

        return $patientFile;
    }

    /**
     * Ensure a directory exists without re-stating it on every chunk in the same request.
     */
    private function ensureDir(string $relPath): void
    {
        if (isset($this->ensuredDirs[$relPath])) {
            return;
        }
        $disk = Storage::disk('local');
        if (!$disk->exists($relPath)) {
            $disk->makeDirectory($relPath);
        }
        $this->ensuredDirs[$relPath] = true;
    }

    /**
     * Resolve MIME type using finfo (no shellout), with a metadata fallback.
     */
    private function resolveMimeType(string $absPath, array $metadata): string
    {
        $mime = finfo_file(finfo_open(FILEINFO_MIME_TYPE), $absPath);
        if ($mime && $mime !== 'application/octet-stream') {
            return $mime;
        }

        return $metadata['mime_type'] ?? 'application/octet-stream';
    }
}