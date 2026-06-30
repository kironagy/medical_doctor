<?php

namespace App\Domains\Media\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Domains\Media\Models\PatientFile;
use App\Domains\Patients\Models\Patient;
use Exception;
use InvalidArgumentException;

class UploadService
{
    /** Merge buffer (4 MiB) — large enough to keep syscalls low for big files. */
    private const MERGE_BUFFER = 4 * 1024 * 1024;

    /** Max total upload size per file (default 50 GiB; override via .env). */
    private const MAX_TOTAL_SIZE = 53687091200; // 50 GiB

    /** Safe extension allow-list for the final stored filename. */
    private const SAFE_EXTENSIONS = [
        'mp4','mov','avi','mkv','webm','m4v','3gp','wmv','flv',
        'jpg','jpeg','png','gif','webp','bmp','heic','tif','tiff',
        'pdf','doc','docx','xls','xlsx','ppt','pptx','txt','csv','rtf',
        'zip','rar','7z',
        'mp3','wav','aac','flac','ogg','m4a',
        'dcm','dicom',
    ];

    /** Per-request directory cache to avoid repeated stat() calls. */
    private array $ensuredDirs = [];

    /**
     * Store an uploaded chunk atomically.
     *
     * Race-safe: directory creation uses a shared lock-free check; chunk files
     * are written to a unique temp name then renamed so a concurrent retry of
     * the same chunk_index never leaves a half-written file.
     */
    public function storeChunk(string $sessionId, UploadedFile $chunk, int $chunkIndex, ?int $expectedChunks = null): void
    {
        $this->assertValidSession($sessionId);

        if ($chunkIndex < 0 || $expectedChunks !== null && $chunkIndex >= $expectedChunks) {
            throw new InvalidArgumentException("Invalid chunk index {$chunkIndex} for session {$sessionId}");
        }

        $start = microtime(true);
        $tmpDir = "tmp/uploads/{$sessionId}";
        $this->ensureDir($tmpDir);

        // Validate contained size against MAX_TOTAL_SIZE *before* writing.
        $disk = Storage::disk('local');
        $receivedSize = 0;
        if ($expectedChunks !== null) {
            foreach ($disk->files($tmpDir) as $f) {
                $receivedSize += $disk->size($f);
            }
        }
        if ($receivedSize + $chunk->getSize() > self::MAX_TOTAL_SIZE) {
            throw new InvalidArgumentException('Upload exceeds maximum allowed size');
        }

        // Atomic write: store to a .part file then rename to chunk_N.
        // Idempotent: re-uploading the same index safely overwrites.
        $finalName = "chunk_{$chunkIndex}";
        $partName = "{$finalName}.part." . Str::random(8);
        $chunk->storeAs($tmpDir, $partName, 'local');

        $absFinal = $disk->path("{$tmpDir}/{$finalName}");
        $absPart = $disk->path("{$tmpDir}/{$partName}");

        // rename() is atomic on POSIX — a competing retry can never observe a
        // partial chunk file.
        if (!@rename($absPart, $absFinal)) {
            @unlink($absPart);
            throw new Exception("Failed to finalize chunk {$chunkIndex}");
        }

        Log::channel('upload')->debug('chunk stored', [
            'session' => $sessionId,
            'index' => $chunkIndex,
            'bytes' => $chunk->getSize(),
            'ms' => round((microtime(true) - $start) * 1000, 2),
        ]);
    }

    /**
     * Indexes of chunks already persisted for a session (for resume).
     */
    public function receivedChunks(string $sessionId): array
    {
        $this->assertValidSession($sessionId);
        $tmpDir = "tmp/uploads/{$sessionId}";
        $disk = Storage::disk('local');

        if (!$disk->exists($tmpDir)) {
            return [];
        }

        $indexes = [];
        foreach ($disk->files($tmpDir) as $file) {
            if (preg_match('#chunk_(\d+)$#', $file, $m)) {
                $indexes[] = (int) $m[1];
            }
        }

        sort($indexes, SORT_NUMERIC);
        return $indexes;
    }

    /**
     * Total byte size of received chunks (cheap progress sanity check).
     */
    public function sessionSize(string $sessionId): int
    {
        $this->assertValidSession($sessionId);
        $tmpDir = "tmp/uploads/{$sessionId}";
        $disk = Storage::disk('local');
        if (!$disk->exists($tmpDir)) return 0;

        $total = 0;
        foreach ($disk->files($tmpDir) as $f) {
            $total += $disk->size($f);
        }
        return $total;
    }

    /**
     * Merge all chunks into the final file, compute SHA-256 in a single pass,
     * create the PatientFile record, and clean up the session.
     */
    public function mergeChunks(string $sessionId, int $totalChunks, Patient $patient, array $fileMetadata, int $uploaderId): PatientFile
    {
        $this->assertValidSession($sessionId);
        $t0 = microtime(true);
        $tmpDir = "tmp/uploads/{$sessionId}";
        $disk = Storage::disk('local');
        $extension = $this->sanitizeExtension($fileMetadata['extension'] ?? 'tmp');

        $fileUuid = (string) Str::uuid();
        $finalRelPath = "patients/{$patient->uuid}/{$fileUuid}.{$extension}";
        $patientDir = "patients/{$patient->uuid}";
        $this->ensureDir($patientDir);

        $finalAbsPath = $disk->path($finalRelPath);

        $final = fopen($finalAbsPath, 'wb');
        if (!$final) {
            throw new Exception("Unable to open final file for writing: {$finalAbsPath}");
        }

        $hashCtx = hash_init('sha256');
        $missing = [];

        for ($i = 0; $i < $totalChunks; $i++) {
            $chunkAbs = $disk->path("{$tmpDir}/chunk_{$i}");

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
                if ($buf === false || $buf === '') break;
                fwrite($final, $buf);
                hash_update($hashCtx, $buf);
            }

            fclose($cf);
            // Do NOT unlink here. If the merge fails mid-way and the job is
            // retried, chunks must still be present. The whole session directory
            // is deleted atomically at the end only on full success.
        }

        // Always close before deciding failure so the temp file can be unlinked.
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
        $type = $this->typeFromMime($mimeType);

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

        // Bridge session_id → file_uuid so the client can follow the async
        // merge + processing pipeline after a browser refresh.
        Cache::put("upload:{$sessionId}", $fileUuid, now()->addHours(6));

        return $patientFile;
    }

    /**
     * Look up the PatientFile produced by a (now-merged) session, if any.
     * Returns null until MergeChunksJob finishes.
     */
    public function sessionFile(string $sessionId): ?array
    {
        $this->assertValidSession($sessionId);

        $uuid = Cache::get("upload:{$sessionId}");
        if (!$uuid) return null;

        $file = PatientFile::where('uuid', $uuid)->first();
        if (!$file) return null;

        return [
            'uuid' => $file->uuid,
            'upload_status' => $file->upload_status,
            'type' => $file->type,
            'thumbnail_url' => $file->thumbnail_url,
            'hls_url' => $file->hls_url,
        ];
    }

    /**
     * Delete an in-progress session (cancel) — safe even if already merged.
     */
    public function deleteSession(string $sessionId): bool
    {
        $this->assertValidSession($sessionId);
        $tmpDir = "tmp/uploads/{$sessionId}";
        $disk = Storage::disk('local');

        if (!$disk->exists($tmpDir)) return true;

        $ok = $disk->deleteDirectory($tmpDir);

        Log::channel('upload')->info('session cancelled', ['session' => $sessionId]);

        return $ok;
    }

    /**
     * Cron-friendly sweep: remove any tmp/uploads/* dir older than the given
     * staleness threshold. Returns count of purged sessions.
     */
    public function purgeAbandonedSessions(int $olderThanSeconds = 6 * 3600): int
    {
        $disk = Storage::disk('local');
        $root = 'tmp/uploads';
        if (!$disk->exists($root)) return 0;

        $cutoff = time() - $olderThanSeconds;
        $purged = 0;
        foreach ($disk->directories($root) as $sessionDir) {
            $abs = $disk->path($sessionDir);
            if (!is_dir($abs)) continue;
            if (filemtime($abs) < $cutoff) {
                $disk->deleteDirectory($sessionDir);
                $purged++;
                Log::channel('upload')->info('session purged (abandoned)', ['session' => basename($sessionDir)]);
            }
        }
        return $purged;
    }

    // ---------- helpers ----------

    private function ensureDir(string $relPath): void
    {
        if (isset($this->ensuredDirs[$relPath])) return;
        $disk = Storage::disk('local');
        if (!$disk->exists($relPath)) {
            $disk->makeDirectory($relPath);
        }
        $this->ensuredDirs[$relPath] = true;
    }

    private function resolveMimeType(string $absPath, array $metadata): string
    {
        $mime = @finfo_file(finfo_open(FILEINFO_MIME_TYPE), $absPath);
        if ($mime && $mime !== 'application/octet-stream') return $mime;
        return $metadata['mime_type'] ?? 'application/octet-stream';
    }

    private function typeFromMime(string $mimeType): string
    {
        return match (true) {
            str_starts_with($mimeType, 'image/') => 'image',
            str_starts_with($mimeType, 'video/') => 'video',
            str_starts_with($mimeType, 'audio/') => 'audio',
            $mimeType === 'application/pdf' => 'pdf',
            str_starts_with($mimeType, 'text/') => 'text',
            default => 'document',
        };
    }

    /**
     * Whitelist the extension for the stored file name. Unknown extensions
     * fall back to "bin" so a malicious client can't plant e.g. ".php".
     */
    private function sanitizeExtension(string $extension): string
    {
        $ext = strtolower(trim($extension, " \t\n\r\0\x0b."));
        $ext = preg_replace('/[^a-z0-9]/', '', $ext) ?? '';
        if ($ext === '' || !in_array($ext, self::SAFE_EXTENSIONS, true)) {
            return 'bin';
        }
        return $ext;
    }

    /**
     * Session IDs are server-issued UUIDs; reject anything that isn't to avoid
     * path traversal / arbitrary directory access.
     */
    private function assertValidSession(string $sessionId): void
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $sessionId)) {
            throw new InvalidArgumentException('Invalid session id');
        }
    }
}