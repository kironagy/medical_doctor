<?php

namespace App\Services\Upload;

use App\Domains\Media\Models\UploadSession;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

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

        $disk = Storage::disk($session->disk);
        $chunkDir = $session->chunkDir();
        if (!$disk->exists($chunkDir)) {
            $disk->makeDirectory($chunkDir);
        }

        $chunkPath = "{$chunkDir}/{$chunkIndex}";
        $tmpPath = "{$chunkDir}/_{$chunkIndex}.tmp";

        $chunkSize = $chunk->getSize();
        \Illuminate\Support\Facades\Log::channel('upload')->debug('chunk received', [
            'session' => $session->uuid,
            'index' => $chunkIndex,
            'size' => $chunkSize,
            'expected_size' => $chunkIndex === $session->total_chunks - 1
                ? ($session->total_size % $session->chunk_size) ?: $session->chunk_size
                : $session->chunk_size,
        ]);

        $chunk->storeAs(dirname($chunkPath), basename($tmpPath), $session->disk);
        if ($disk->exists($tmpPath)) {
            $disk->move($tmpPath, $chunkPath);
        }

        $checksum = $this->checksumService->chunkChecksum($chunk);

        $received = $this->validationService->receivedChunks($session);
        $progress = $session->total_chunks > 0
            ? (int) round((count($received) / $session->total_chunks) * 100)
            : 0;

        return [
            'chunk_index' => $chunkIndex,
            'checksum' => $checksum,
            'received_chunks' => count($received),
            'total_chunks' => $session->total_chunks,
            'progress' => $progress,
        ];
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
        $session->update(['status' => 'cancelled']);
    }
}
