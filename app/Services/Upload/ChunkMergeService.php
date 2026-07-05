<?php

namespace App\Services\Upload;

use App\Domains\Media\Models\UploadSession;
use App\Domains\Media\Models\PatientFile;
use App\Domains\Media\Jobs\GenerateThumbnailJob;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

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
        $this->validationService->validateComplete($session);

        $disk = Storage::disk($session->disk);
        $patient = $session->patient;

        $fileUuid = (string) Str::uuid();
        $extension = $session->extension;

        // Determine final file path: if direct-write mode, use session's final_path; else build new path
        if ($session->final_path) {
            $finalRelPath = $session->final_path;
            $finalAbsPath = $disk->path($finalRelPath);
            // Verify file exists and size matches expectation (optional)
            if (!file_exists($finalAbsPath)) {
                throw new \RuntimeException("Final file not found after direct write: {$finalAbsPath}");
            }
            $size = filesize($finalAbsPath) ?: 0;
            // Note: checksum computation skipped for immediate availability; could be done async
        } else {
            // Legacy: perform actual merge from temporary chunks
            $chunkDir = $session->chunkDir();
            $finalRelPath = "patients/{$session->patient->uuid}/{$fileUuid}.{$extension}";
            $patientDir = "patients/{$session->patient->uuid}";

            if (!$disk->exists($patientDir)) {
                $disk->makeDirectory($patientDir);
            }

            $finalAbsPath = $disk->path($finalRelPath);
            $finalStream = fopen($finalAbsPath, 'wb');
            if (!$finalStream) {
                throw new \RuntimeException("Cannot open final file for writing: {$finalAbsPath}");
            }

            $hashCtx = hash_init('sha256');
            $t0 = microtime(true);
            $peakMem = 0;
            $totalStreamed = 0;

            for ($i = 0; $i < $session->total_chunks; $i++) {
                $chunkAbs = $disk->path("{$chunkDir}/{$i}");
                if (!file_exists($chunkAbs)) {
                    fclose($finalStream);
                    @unlink($finalAbsPath);
                    throw new \RuntimeException("Missing chunk {$i} during merge");
                }
                $chunkStream = fopen($chunkAbs, 'rb');
                if (!$chunkStream) {
                    fclose($finalStream);
                    @unlink($finalAbsPath);
                    throw new \RuntimeException("Cannot read chunk {$i}");
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

            $wholeFileInMemory = $peakMem >= ($size * 0.8);
            if ($wholeFileInMemory) {
                Log::channel('upload')->warning('CRITICAL — Merge appears to be loading the entire file into memory', [
                    'session' => $session->uuid,
                    'file_size' => $size,
                    'peak_memory' => $peakMem,
                    'buffer_size' => self::MERGE_BUFFER,
                ]);
            }

            Log::channel('upload')->info('chunks merged', [
                'session' => $session->uuid,
                'file_uuid' => $fileUuid,
                'chunks' => $session->total_chunks,
                'size' => $size,
                'merge_seconds' => round($mergeTime, 3),
                'hash' => $finalHash,
                'streamed_bytes' => $totalStreamed,
                'peak_memory' => $peakMem,
                'buffer_size' => self::MERGE_BUFFER,
                'whole_file_in_memory' => $wholeFileInMemory,
            ]);
        }

        $mimeType = $session->mime_type;
        $type = $this->typeFromMime($mimeType);

        $patientFile = PatientFile::create([
            'uuid' => $fileUuid,
            'patient_id' => $session->patient_id,
            'uploaded_by_id' => $session->user_id,
            'title' => $session->metadata['title'] ?? pathinfo($session->original_name, PATHINFO_FILENAME),
            'desc' => $session->metadata['desc'] ?? null,
            'type' => $type,
            'mime_type' => $mimeType,
            'size' => $size,
            'category' => $session->metadata['category'] ?? null,
            'date' => $session->metadata['date'] ?? now(),
            'file_name' => $session->original_name,
            'file_path' => $finalRelPath,
            'upload_status' => 'ready',
        ]);

        // Cleanup legacy chunk directory if exists
        if (!$session->final_path) {
            $disk->deleteDirectory($chunkDir);
        }

        $this->sessionService->markCompleted($session->uuid, null); // checksum not available in direct mode

        if ($type === 'video') {
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
