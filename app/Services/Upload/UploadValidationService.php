<?php

namespace App\Services\Upload;

use App\Domains\Media\Models\UploadSession;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class UploadValidationService
{
    public const MAX_FILE_SIZE = 5 * 1024 * 1024 * 1024;
    public const ALLOWED_MIMES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp', 'image/tiff', 'image/heic', 'image/heif',
        'video/mp4', 'video/mpeg', 'video/quicktime', 'video/x-msvideo', 'video/webm', 'video/x-matroska',
        'video/3gpp', 'video/3gpp2', 'video/x-ms-wmv', 'video/x-flv', 'video/x-m4v',
        'application/pdf',
        'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'text/plain', 'text/csv', 'text/rtf',
        'application/zip', 'application/x-rar-compressed', 'application/x-7z-compressed',
        'audio/mpeg', 'audio/wav', 'audio/aac', 'audio/flac', 'audio/ogg', 'audio/mp4',
        'application/dicom', 'application/octet-stream',
    ];

    public const SAFE_EXTENSIONS = [
        'mp4','mov','avi','mkv','webm','m4v','3gp','3g2','wmv','flv',
        'jpg','jpeg','png','gif','webp','bmp','heic','heif','tif','tiff',
        'pdf','doc','docx','xls','xlsx','ppt','pptx','txt','csv','rtf',
        'zip','rar','7z',
        'mp3','wav','aac','flac','ogg','m4a',
        'dcm','dicom',
    ];

    public static function isMimeAllowed(string $mime): bool
    {
        return in_array(strtolower(trim($mime)), self::ALLOWED_MIMES, true);
    }

    public static function isExtensionAllowed(string $ext): bool
    {
        return in_array(strtolower(trim($ext)), self::SAFE_EXTENSIONS, true);
    }

    public static function isFileAllowed(UploadedFile $file): bool
    {
        $mime = $file->getMimeType();
        $ext = strtolower($file->getClientOriginalExtension());
        if ($mime && self::isMimeAllowed($mime)) {
            return true;
        }
        return $ext ? self::isExtensionAllowed($ext) : false;
    }

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
        if (!self::isMimeAllowed($data['mime_type'])) {
            // Also check extension fallback if mime_type is generic application/octet-stream
            $ext = strtolower(pathinfo($data['file_name'], PATHINFO_EXTENSION));
            if (!$ext || !self::isExtensionAllowed($ext)) {
                throw new HttpException(422, 'Unsupported file type: ' . $data['mime_type']);
            }
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

        // ── FIX-REL-3 item 3: exact-size validation, no tolerance. ──────────
        // writeChunkDirect() writes at a FIXED stride (chunkIndex * chunk_size).
        // A chunk larger than chunk_size overwrites the start of the next
        // chunk's byte range; the previous 1 MiB tolerance made that silently
        // possible. An offset-addressed write cannot tolerate variable-length
        // chunks, so every chunk except the last must match chunk_size exactly,
        // and the last must match the file's remainder exactly.
        $isLastChunk = $chunkIndex === $session->total_chunks - 1;
        $expectedSize = $isLastChunk
            ? $session->total_size - ($chunkIndex * $session->chunk_size)
            : $session->chunk_size;

        if ($chunk->getSize() !== $expectedSize) {
            throw new HttpException(422, "Chunk {$chunkIndex} size {$chunk->getSize()} does not match expected size {$expectedSize}");
        }
    }

    public function validateComplete(UploadSession $session): void
    {
        if ($session->status !== 'uploading') {
            throw new HttpException(400, 'Session is not in uploading state');
        }

        // Use DB count for race-safe verification
        $receivedCount = DB::table('upload_chunk_receipts')
            ->where('session_id', $session->id)
            ->count();

        if ($receivedCount < $session->total_chunks) {
            $missing = $session->total_chunks - $receivedCount;
            throw new HttpException(400, "Missing {$missing} chunk(s)");
        }
    }

    public function missingChunks(UploadSession $session): array
    {
        // Return empty if all chunks received per DB count
        $receivedCount = DB::table('upload_chunk_receipts')
            ->where('session_id', $session->id)
            ->count();
        if ($receivedCount >= $session->total_chunks) {
            return [];
        }

        // Fallback: detailed diff if count indicates missing
        $received = $this->receivedChunks($session);
        $all = range(0, $session->total_chunks - 1);
        return array_values(array_diff($all, $received));
    }

    public function receivedChunks(UploadSession $session): array
    {
        // Use DB-backed receipts for optimized direct-write sessions
        if ($session->final_path) {
            $chunks = DB::table('upload_chunk_receipts')
                ->where('session_id', $session->id)
                ->orderBy('chunk_index')
                ->pluck('chunk_index')
                ->all();
            return array_map('intval', $chunks);
        }

        // Legacy: filesystem-based chunk detection
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
