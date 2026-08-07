<?php

namespace App\Services\Upload;

use App\Domains\Media\Models\UploadSession;
use App\Domains\Media\Models\PatientFile;
use App\Domains\Media\Jobs\GenerateThumbnailJob;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ChunkMergeService
{
    private const MERGE_BUFFER = 4 * 1024 * 1024;

    public function __construct(
        private readonly UploadValidationService $validationService,
        private readonly UploadChecksumService $checksumService,
        private readonly UploadSessionService $sessionService,
    ) {}

    public function merge(UploadSession $session): PatientFile
    {
        $startMs = microtime(true);
        Log::channel('upload')->info('merge:start', ['session' => $session->uuid]);

        // Validate inside transaction and lock session to prevent concurrent complete
        $patientFile = DB::transaction(function () use ($session) {
            $locked = UploadSession::where('id', $session->id)->lockForUpdate()->firstOrFail();
            $this->validationService->validateComplete($locked);

            $disk = Storage::disk($locked->disk);
            $patient = $locked->patient;

            $fileUuid = (string) Str::uuid();
            $extension = $locked->extension;

            // Determine final file path
            if ($locked->final_path) {
                $finalRelPath = $locked->final_path;
                $finalAbsPath = $disk->path($finalRelPath);
                // Verify final file exists and size matches the locked session's
                // declared total_size — a merge that silently wrote a truncated
                // or oversized file must never become a PatientFile row.
                if (!file_exists($finalAbsPath)) {
                    throw new RuntimeException("Final file not found after direct write: {$finalAbsPath}");
                }
                $size = filesize($finalAbsPath) ?: 0;
                if ($size === 0) {
                    throw new RuntimeException("Direct-write file is empty: {$finalAbsPath}");
                }
                if ($size !== (int) $locked->total_size) {
                    throw new RuntimeException(
                        "Direct-write file size mismatch for session {$locked->uuid}: " .
                        "expected {$locked->total_size} bytes, got {$size} bytes at {$finalAbsPath}"
                    );
                }

                // ── FIX-REL-3 item 1: checksum bypass on direct-write path.
                // The legacy chunked-merge path already computes sha256 by
                // streaming every chunk through hash_update(). The direct-write
                // path skipped this entirely, so a truncated or bit-flipped
                // video was accepted as valid and markCompleted was called with
                // a null hash. hash_file() streams at C level — no full-file
                // PHP buffer — safe for videos of any size.
                $finalHash = hash_file('sha256', $finalAbsPath) ?: null;
                Log::channel('upload')->info('merge:direct_write_checksum', [
                    'session'  => $locked->uuid,
                    'size'     => $size,
                    'hash'     => $finalHash,
                ]);
            } else {
                // Legacy: perform actual merge from temporary chunks
                $chunkDir = $locked->chunkDir();
                $finalRelPath = "patients/{$locked->patient->uuid}/{$fileUuid}.{$extension}";
                $patientDir = "patients/{$locked->patient->uuid}";

                if (!$disk->exists($patientDir)) {
                    $disk->makeDirectory($patientDir);
                }

                $finalAbsPath = $disk->path($finalRelPath);
                $finalStream = fopen($finalAbsPath, 'wb');
                if (!$finalStream) {
                    throw new RuntimeException("Cannot open final file for writing: {$finalAbsPath}");
                }

                $hashCtx = hash_init('sha256');
                $t0 = microtime(true);
                $peakMem = 0;
                $totalStreamed = 0;

                for ($i = 0; $i < $locked->total_chunks; $i++) {
                    $chunkAbs = $disk->path("{$chunkDir}/{$i}");
                    if (!file_exists($chunkAbs)) {
                        fclose($finalStream);
                        @unlink($finalAbsPath);
                        throw new RuntimeException("Missing chunk {$i} during merge");
                    }
                    $chunkStream = fopen($chunkAbs, 'rb');
                    if (!$chunkStream) {
                        fclose($finalStream);
                        @unlink($finalAbsPath);
                        throw new RuntimeException("Cannot read chunk {$i}");
                    }
                    $chunkBytes = 0;
                    while (!feof($chunkStream)) {
                        $buf = fread($chunkStream, self::MERGE_BUFFER);
                        if ($buf === false) break;
                        $bytes = strlen($buf);
                        fwrite($finalStream, $buf);
                        hash_update($hashCtx, $buf);
                        $chunkBytes += $bytes;
                        $totalStreamed += $bytes;
                    }
                    fclose($chunkStream);
                    @unlink($chunkAbs);
                    $peakMem = max($peakMem, memory_get_peak_usage(true));
                }

                fclose($finalStream);
                $mergeTime = microtime(true) - $t0;
                $finalHash = hash_final($hashCtx);
                $size = filesize($finalAbsPath) ?: 0;

                if ($size !== (int) $locked->total_size) {
                    @unlink($finalAbsPath);
                    throw new RuntimeException(
                        "Merged file size mismatch for session {$locked->uuid}: " .
                        "expected {$locked->total_size} bytes, got {$size} bytes at {$finalAbsPath}"
                    );
                }

                $wholeFileInMemory = $peakMem >= ($size * 0.8);
                if ($wholeFileInMemory) {
                    Log::channel('upload')->warning('merge:high_memory', [
                        'session' => $locked->uuid,
                        'file_size' => $size,
                        'peak_memory' => $peakMem,
                        'buffer_size' => self::MERGE_BUFFER,
                    ]);
                }

                Log::channel('upload')->info('merge:chunks_merged', [
                    'session'        => $locked->uuid,
                    'file_uuid'      => $fileUuid,
                    'chunks'         => $locked->total_chunks,
                    'size'           => $size,
                    'merge_seconds'  => round($mergeTime, 3),
                    'hash'           => $finalHash,
                    'streamed_bytes' => $totalStreamed,
                    'peak_memory'    => $peakMem,
                    'buffer_size'    => self::MERGE_BUFFER,
                    'whole_in_mem'   => $wholeFileInMemory,
                ]);
            }

            // After ensuring file exists and size matches
            $mimeType = $locked->mime_type;
            $type = $this->typeFromMime($mimeType);

            if ($type === 'document' && $locked->original_name) {
                $ext = strtolower(pathinfo($locked->original_name, PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'heic', 'tiff', 'tif'])) {
                    $type = 'image';
                    $mimeType = match($ext) {
                        'jpg', 'jpeg' => 'image/jpeg',
                        'png' => 'image/png',
                        'gif' => 'image/gif',
                        'webp' => 'image/webp',
                        'heic' => 'image/heic',
                        default => 'image/' . $ext,
                    };
                } elseif (in_array($ext, ['mp4', 'mov', 'avi', 'mkv', 'webm', '3gp', 'wmv', 'flv'])) {
                    $type = 'video';
                    $mimeType = match($ext) {
                        'mp4' => 'video/mp4',
                        'mov' => 'video/quicktime',
                        'avi' => 'video/x-msvideo',
                        'mkv' => 'video/x-matroska',
                        'webm' => 'video/webm',
                        default => 'video/' . $ext,
                    };
                }
            }

            $uploadedById = $locked->user_id ?: ($patient->primary_doctor_id ?? \App\Domains\Users\Models\User::value('id') ?? 1);

            $patientFile = PatientFile::create([
                'uuid'              => $fileUuid,
                'patient_id'        => $locked->patient_id,
                'uploaded_by_id'    => $uploadedById,
                'title'             => $locked->metadata['title'] ?? pathinfo($locked->original_name, PATHINFO_FILENAME),
                'desc'              => $locked->metadata['desc'] ?? null,
                'type'              => $type,
                'mime_type'         => $mimeType,
                'size'              => $size ?? $locked->total_size,
                'category'          => $locked->metadata['category'] ?? null,
                'date'              => $locked->metadata['date'] ?? now(),
                'file_name'         => $locked->original_name,
                'file_path'         => $finalRelPath,
                'upload_status'     => 'ready',
                // ── BUG-007 FIX: Set pending_sync on SQLite, synced on MySQL ──
                'sync_status'       => config('database.default') === 'sqlite' ? 'pending_sync' : 'synced',
            ]);

            // Cleanup legacy chunk directory
            if (!$locked->final_path) {
                $disk->deleteDirectory($chunkDir);
            }

            // Mark session completed (using $locked, not $session).
            // $finalHash is always set by this point: the legacy branch sets it
            // via hash_final() at line ~103; the direct-write branch now sets it
            // via hash_file() above. The ?? null fallback is kept as a safety net.
            $this->sessionService->markCompleted($locked->uuid, $finalHash ?? null);

            // ── FIX-PERF-10: GenerateThumbnailJob::dispatch moved OUTSIDE this
            // transaction (see below). With QUEUE_CONNECTION=sync (required on
            // device; also the production default) the job runs synchronously
            // and ffmpeg can take up to 60 s, holding the FOR UPDATE row lock
            // on upload_sessions the entire time. Under concurrent video
            // completions this causes InnoDB lock-wait timeouts -> 500 errors.
            // Moved to after DB::transaction returns, below.

            return $patientFile;
        });

        $durationMs = (microtime(true) - $startMs) * 1000;
        Log::channel('upload')->info('merge:complete', [
            'session'     => $session->uuid,
            'file_uuid'   => $patientFile->uuid,
            'duration_ms' => round($durationMs, 2),
        ]);

        // ── FIX-PERF-10: dispatch AFTER the transaction commits so ffmpeg does
        // not run while the upload_sessions FOR UPDATE lock is still held.
        // GenerateThumbnailJob is a no-op on SQLite (early return at line 44),
        // so this only matters on MySQL/production. Behaviour is otherwise
        // identical: the file record already exists when dispatch() is called.
        if ($patientFile->type === 'video') {
            GenerateThumbnailJob::dispatch($patientFile->id);
        }

        return $patientFile;
    }

    private function typeFromMime(string $mime): string
    {
        return match (true) {
            str_starts_with($mime, 'image/') => 'image',
            str_starts_with($mime, 'video/') => 'video',
            str_starts_with($mime, 'audio/') => 'audio',
            $mime === 'application/pdf' => 'pdf',
            str_starts_with($mime, 'text/') => 'text',
            default => 'document',
        };
    }
}
