<?php

namespace App\Services\Upload;

use App\Domains\Media\Models\UploadSession;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ChunkUploadService
{
    public function __construct(
        private readonly UploadValidationService $validationService,
        private readonly UploadChecksumService $checksumService,
        private readonly UploadSessionService $sessionService,
    ) {}

    public function storeChunk(UploadSession $session, UploadedFile $chunk, int $chunkIndex): array
    {
        $startMs = microtime(true);

        try {
            // Ensure session is in uploading state (atomic)
            $session = $this->sessionService->ensureUploading($session);
            if ($session->status !== 'uploading') {
                throw new HttpException(400, 'Session is not in uploading state');
            }

            $this->validationService->validateChunk($session, $chunk, $chunkIndex);

            // Direct-write optimization
            if ($session->final_path) {
                $this->writeChunkDirect($session, $chunk, $chunkIndex);
            } else {
                $this->writeChunkLegacy($session, $chunk, $chunkIndex);
            }

            // Atomic receipt record (idempotent)
            $this->recordChunkReceipt($session, $chunkIndex);

            $received = $this->validationService->receivedChunks($session);
            $progress = $session->total_chunks > 0
                ? (int) round((count($received) / $session->total_chunks) * 100)
                : 0;

            $checksum = $this->checksumService->chunkChecksum($chunk);

            $durationMs = (microtime(true) - $startMs) * 1000;

            Log::channel('upload')->info('chunk:stored', [
                'session'      => $session->uuid,
                'chunk'        => $chunkIndex,
                'size'         => $chunk->getSize(),
                'received_cnt' => count($received),
                'progress'     => $progress,
                'duration_ms'  => round($durationMs, 2),
            ]);

            return [
                'chunk_index'      => $chunkIndex,
                'checksum'         => $checksum,
                'received_chunks'  => count($received),
                'total_chunks'     => $session->total_chunks,
                'progress'         => $progress,
                'server_time_ms'   => round($durationMs, 2),
            ];
        } catch (\Throwable $e) {
            Log::channel('upload')->error('chunk:error', [
                'session'   => $session->uuid,
                'chunk'     => $chunkIndex,
                'error'     => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    private function writeChunkDirect(UploadSession $session, UploadedFile $chunk, int $chunkIndex): void
    {
        $disk = Storage::disk($session->disk);
        $finalRelPath = $session->final_path;
        $finalAbsPath = $disk->path($finalRelPath);
        $offset = $chunkIndex * $session->chunk_size;

        $dir = dirname($finalAbsPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $fp = fopen($finalAbsPath, 'c+');
        if (!$fp) {
            throw new HttpException(500, "Cannot open file for writing");
        }

        if (flock($fp, LOCK_EX)) {
            try {
                if (fseek($fp, $offset) !== 0) {
                    throw new HttpException(500, "Failed to seek in file");
                }
                $content = $chunk->getContent();
                $written = fwrite($fp, $content);
                fflush($fp);
                if ($written !== strlen($content)) {
                    throw new HttpException(500, "Incomplete chunk write");
                }
            } finally {
                flock($fp, LOCK_UN);
                fclose($fp);
            }
        } else {
            fclose($fp);
            throw new HttpException(500, "Could not lock file for writing");
        }
    }

    private function writeChunkLegacy(UploadSession $session, UploadedFile $chunk, int $chunkIndex): void
    {
        $disk = Storage::disk($session->disk);
        $chunkDir = $session->chunkDir();
        if (!$disk->exists($chunkDir)) {
            $disk->makeDirectory($chunkDir);
        }

        $chunkPath = "{$chunkDir}/{$chunkIndex}";
        $tmpPath = "{$chunkDir}/_{$chunkIndex}.tmp";

        $chunk->storeAs(dirname($chunkPath), basename($tmpPath), $session->disk);
        if ($disk->exists($tmpPath)) {
            $disk->move($tmpPath, $chunkPath);
        }
    }

    private function recordChunkReceipt(UploadSession $session, int $chunkIndex): void
    {
        // Insert with duplicate ignore; safe for retries
        $affected = DB::table('upload_chunk_receipts')->insertOrIgnore([
            'session_id' => $session->id,
            'chunk_index' => $chunkIndex,
            'received_at' => DB::raw('CURRENT_TIMESTAMP'),
        ]);

        // Also keep the json column in sessions in sync for quick access (best effort)
        if ($affected) {
            DB::table('upload_sessions')
                ->where('id', $session->id)
                ->update([
                    'received_chunk_indexes' => DB::raw("JSON_ARRAY_APPEND(COALESCE(received_chunk_indexes, '[]'), '$', $chunkIndex)"),
                    'updated_at' => now()->toDateTimeString(),
                ]);
        }
    }

    public function getStatus(UploadSession $session): array
    {
        $received = $this->validationService->receivedChunks($session);
        sort($received);
        $progress = $session->total_chunks > 0
            ? (int) round((count($received) / $session->total_chunks) * 100)
            : 0;

        return [
            'uuid'               => $session->uuid,
            'status'             => $session->status,
            'total_chunks'       => $session->total_chunks,
            'received_chunks'    => $received,
            'received_count'     => count($received),
            'progress'           => $progress,
            'total_size'         => $session->total_size,
            'original_name'      => $session->original_name,
        ];
    }

    public function cancel(UploadSession $session): void
    {
        $disk = Storage::disk($session->disk);
        $chunkDir = $session->chunkDir();
        if ($disk->exists($chunkDir)) {
            $disk->deleteDirectory($chunkDir);
        }
        if ($session->final_path && $disk->exists($session->final_path)) {
            $disk->delete($session->final_path);
        }
        $session->update(['status' => 'cancelled']);
    }
}
