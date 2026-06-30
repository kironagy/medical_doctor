<?php

namespace App\Domains\Media\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use App\Domains\Media\Models\PatientFile;
use App\Domains\Patients\Models\Patient;
use Exception;

class UploadService
{
    public function storeChunk(string $sessionId, UploadedFile $chunk, int $chunkIndex): void
    {
        $tmpPath = "tmp/uploads/{$sessionId}";
        if (!Storage::disk('local')->exists($tmpPath)) {
            Storage::disk('local')->makeDirectory($tmpPath);
        }
        
        $chunk->storeAs($tmpPath, "chunk_{$chunkIndex}", 'local');
    }

    public function mergeChunks(string $sessionId, int $totalChunks, Patient $patient, array $fileMetadata, int $uploaderId): PatientFile
    {
        $tmpPath = "tmp/uploads/{$sessionId}";
        $extension = $fileMetadata['extension'] ?? 'tmp';
        
        $fileUuid = (string) Str::uuid();
        $finalPath = "patients/{$patient->uuid}/{$fileUuid}.{$extension}";
        
        // Ensure destination dir exists
        if (!Storage::disk('local')->exists("patients/{$patient->uuid}")) {
            Storage::disk('local')->makeDirectory("patients/{$patient->uuid}");
        }

        $finalFileAbsolutePath = Storage::disk('local')->path($finalPath);
        $finalFile = fopen($finalFileAbsolutePath, 'wb');

        for ($i = 0; $i < $totalChunks; $i++) {
            $chunkPath = Storage::disk('local')->path("{$tmpPath}/chunk_{$i}");
            if (!file_exists($chunkPath)) {
                fclose($finalFile);
                if (file_exists($finalFileAbsolutePath)) unlink($finalFileAbsolutePath);
                throw new Exception("Missing chunk {$i} for session {$sessionId}");
            }
            
            $chunkFile = fopen($chunkPath, 'rb');
            stream_copy_to_stream($chunkFile, $finalFile);
            fclose($chunkFile);
            
            unlink($chunkPath);
        }

        fclose($finalFile);
        Storage::disk('local')->deleteDirectory($tmpPath);
        
        $hash = hash_file('sha256', $finalFileAbsolutePath);
        
        $mimeType = File::mimeType($finalFileAbsolutePath) ?: 'application/octet-stream';
        $size = File::size($finalFileAbsolutePath) ?: 0;

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
            'file_path' => $finalPath,
            'upload_status' => 'queued',
            'video_metadata' => ['hash' => $hash],
        ]);

        return $patientFile;
    }
}
