<?php

namespace App\Services\Upload;

use App\Domains\Media\Models\UploadSession;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ChunkUploadService
{
    public function __construct(
        private readonly UploadValidationService $validationService,
        private readonly UploadChecksumService $checksumService,
    ) {}

    public function storeChunk(UploadSession $session, UploadedFile $chunk, int $chunkIndex): array
    {
        $this->validationService->validateChunk($session, $chunk, $chunkIndex);

        if ($session->status === 'pending') {
            $session->update(['status' => 'uploading']);
        }

        // Optimized direct-write path: write chunk directly to final file at offset
        if ($session->final_path) {
            $this->writeChunkDirect($session, $chunk, $chunkIndex);
        } else {
            $this->writeChunkLegacy($session, $chunk, $chunkIndex);
        }

        // Record receipt in DB (works for both modes; in legacy mode we also have temp files)
        $this->recordChunkReceipt($session, $chunkIndex);

        $received = $this->validationService->receivedChunks($session);
        $progress = $session->total_chunks > 0
            ? (int) round((count($received) / $session->total_chunks) * 100)
            : 0;

        $checksum = $this->checksumService->chunkChecksum($chunk);

        return [
            'chunk_index' => $chunkIndex,
            'checksum' => $checksum,
            'received_chunks' => count($received),
            'total_chunks' => $session->total_chunks,
            'progress' => $progress,
        ];
    }

    private function writeChunkDirect(UploadSession $session, UploadedFile $chunk, int $chunkIndex): void
    {
        $disk = Storage::disk($session->disk);
        $finalRelPath = $session->final_path;
        $finalAbsPath = $disk->path($finalRelPath);
        $offset = $chunkIndex * $session->chunk_size;

        // Ensure directory exists
        $dir = dirname($finalAbsPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $fp = fopen($finalAbsPath, 'c+');
        if (!$fp) {
            throw new \RuntimeException("Cannot open file for writing: {$finalAbsPath}");
        }
        if (fseek($fp, $offset) !== 0) {
            fclose($fp);
            throw new \RuntimeException("Failed to seek to offset {$offset} in file {$finalAbsPath}");
        }

        $content = $chunk->getContent();
        $written = fwrite($fp, $content);
        fflush($fp);
        fclose($fp);

        if ($written !== strlen($content)) {
            // Partial write could corrupt file; attempt cleanup
            @unlink($finalAbsPath);
            throw new \RuntimeException("Failed to write full chunk {$chunkIndex} to file");
        }

        Log::channel('upload')->debug('chunk written directly', [
            'session' => $session->uuid,
            'index' => $chunkIndex,
            'offset' => $offset,
            'size' => strlen($content),
        ]);
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

        $chunkSize = $chunk->getSize();
        Log::channel('upload')->debug('chunk received (legacy)', [
            'session' => $session->uuid,
            'index' => $chunkIndex,
            'size' => $chunkSize,
        ]);

        $chunk->storeAs(dirname($chunkPath), basename($tmpPath), $session->disk);
        if ($disk->exists($tmpPath)) {
            $disk->move($tmpPath, $chunkPath);
        }
    }

    private function recordChunkReceipt(UploadSession $session, int $chunkIndex): void
    {
        // Atomically insert receipt; ignore duplicate (retry of same chunk)
        DB::table('upload_chunk_receipts')->insertOrIgnore([
            'session_id' => $session->id,
            'chunk_index' => $chunkIndex,
            'received_at' => DB::raw('CURRENT_TIMESTAMP'),
        ]);
    }

    public function getStatus(UploadSession $session): array
    {
        $received = $this->validationService->receivedChunks($session);
        $progress = $session->total_chunks > 0
            ? (int) round((count($received) / $session->total_chunks) * 100)
            : 0;

        return [
            'uuid' => $session->uuid,
            'status' => $session->status,
            'total_chunks' => $session->total_chunks,
            'received_chunks' => $received,
            'received_count' => count($received),
            'progress' => $progress,
            'total_size' => $session->total_size,
            'original_name' => $session->original_name,
        ];
    }

    public function cancel(UploadSession $session): void
    {
        $disk = Storage::disk($session->disk);
        $chunkDir = $session->chunkDir();
        if ($disk->exists($chunkDir)) {
            $disk->deleteDirectory($chunkDir);
        }
        // Cleanup direct-written partial file if exists
        if ($session->final_path && $disk->exists($session->final_path)) {
            $disk->delete($session->final_path);
        }
        $session->update(['status' => 'cancelled']);
    }
}
