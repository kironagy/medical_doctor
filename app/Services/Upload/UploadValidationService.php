<?php

namespace App\Services\Upload;

use App\Domains\Media\Models\UploadSession;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpKernel\Exception\HttpException;

class UploadValidationService
{
    private const MAX_FILE_SIZE = 5 * 1024 * 1024 * 1024;
    private const ALLOWED_MIMES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp', 'image/tiff', 'image/heic',
        'video/mp4', 'video/mpeg', 'video/quicktime', 'video/x-msvideo', 'video/webm', 'video/x-matroska',
        'application/pdf',
        'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'text/plain', 'text/csv', 'text/rtf',
        'application/zip', 'application/x-rar-compressed', 'application/x-7z-compressed',
        'audio/mpeg', 'audio/wav', 'audio/aac', 'audio/flac', 'audio/ogg', 'audio/mp4',
        'application/dicom',
    ];

    public function validateInit(array $data): void
    {
        if (empty($data['file_name']) || strlen($data['file_name']) > 255) {
            throw new HttpException(422, 'Invalid or missing file name');
        }
        if (empty($data['file_size']) || $data['file_size'] < 1) {
            throw new HttpException(422, 'Invalid file size');
        }
        if ($data['file_size'] > self::MAX_FILE_SIZE) {
            throw new HttpException(422, 'File exceeds maximum allowed size of 5GB');
        }
        if (empty($data['mime_type'])) {
            throw new HttpException(422, 'Missing MIME type');
        }
        if (empty($data['patient_id'])) {
            throw new HttpException(422, 'Missing patient ID');
        }
    }

    public function validateChunk(UploadSession $session, UploadedFile $chunk, int $chunkIndex): void
    {
        if ($session->status !== 'pending' && $session->status !== 'uploading') {
            throw new HttpException(400, 'Session is not active');
        }
        if ($chunkIndex < 0 || $chunkIndex >= $session->total_chunks) {
            throw new HttpException(422, "Invalid chunk index {$chunkIndex}");
        }
        if ($chunk->getError() !== UPLOAD_ERR_OK) {
            throw new HttpException(422, 'Chunk upload error');
        }
        $isLastChunk = $chunkIndex === $session->total_chunks - 1;
        $expectedSize = $isLastChunk
            ? ($session->total_size % $session->chunk_size) ?: $session->chunk_size
            : $session->chunk_size;
        $tolerance = 64;
        if ($chunk->getSize() > $expectedSize + $tolerance) {
            throw new HttpException(422, "Chunk {$chunkIndex} exceeds expected size");
        }
    }

    public function validateComplete(UploadSession $session): void
    {
        if ($session->status !== 'uploading') {
            throw new HttpException(400, 'Session is not in uploading state');
        }
        $missing = $this->missingChunks($session);
        if (!empty($missing)) {
            throw new HttpException(400, 'Missing chunks: ' . implode(', ', $missing));
        }
    }

    public function missingChunks(UploadSession $session): array
    {
        $disk = \Illuminate\Support\Facades\Storage::disk($session->disk);
        $chunkDir = $session->chunkDir();
        if (!$disk->exists($chunkDir)) {
            return range(0, $session->total_chunks - 1);
        }
        $missing = [];
        for ($i = 0; $i < $session->total_chunks; $i++) {
            $path = "{$chunkDir}/{$i}";
            if (!$disk->exists($path)) {
                $missing[] = $i;
            }
        }
        return $missing;
    }

    public function receivedChunks(UploadSession $session): array
    {
        $disk = \Illuminate\Support\Facades\Storage::disk($session->disk);
        $chunkDir = $session->chunkDir();
        if (!$disk->exists($chunkDir)) {
            return [];
        }
        $chunks = [];
        foreach ($disk->files($chunkDir) as $file) {
            $basename = basename($file);
            if (ctype_digit($basename) || (is_numeric($basename) && strpos($basename, '.') === false)) {
                $chunks[] = (int) $basename;
            }
        }
        sort($chunks);
        return $chunks;
    }
}
