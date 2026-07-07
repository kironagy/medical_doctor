<?php

namespace App\Domains\Media\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use App\Models\PatientFile;
use App\Jobs\GenerateThumbnailJob;
use App\Models\Patient;
use Exception;

class UploadService
{
    private const SAFE_EXTENSIONS = [
        'mp4','mov','avi','mkv','webm','m4v','3gp','wmv','flv',
        'jpg','jpeg','png','gif','webp','bmp','heic','tif','tiff',
        'pdf','doc','docx','xls','xlsx','ppt','pptx','txt','csv','rtf',
        'zip','rar','7z',
        'mp3','wav','aac','flac','ogg','m4a',
        'dcm','dicom',
    ];

    public function uploadFile(UploadedFile $file, int $patientId, int $uploaderId, array $metadata = []): PatientFile
    {
        $originalName = $file->getClientOriginalName();
        $extension = $this->sanitizeExtension($file->getClientOriginalExtension());
        $mimeType = $file->getMimeType();
        $type = $this->typeFromMime($mimeType);

        $patientUuid = Patient::where('id', $patientId)->value('uuid');
        $fileUuid = (string) Str::uuid();
        $fileName = "{$fileUuid}.{$extension}";
        $relPath = "patients/{$patientUuid}/{$fileName}";

        $disk = Storage::disk('local');
        $disk->putFileAs("patients/{$patientUuid}", $file, $fileName);

        $patientFile = PatientFile::create([
            'uuid' => $fileUuid,
            'patient_id' => $patientId,
            'uploaded_by_id' => $uploaderId,
            'title' => $metadata['title'] ?? pathinfo($originalName, PATHINFO_FILENAME),
            'desc' => $metadata['desc'] ?? null,
            'type' => $type,
            'mime_type' => $mimeType,
            'size' => $file->getSize(),
            'category' => $metadata['category'] ?? null,
            'date' => $metadata['date'] ?? now(),
            'file_name' => $originalName,
            'file_path' => $relPath,
            'upload_status' => 'ready',
        ]);

        if ($type === 'video') {
            GenerateThumbnailJob::dispatch($patientFile->id);
        }

        Log::channel('upload')->info('file uploaded', [
            'patient' => $patientId,
            'file_uuid' => $fileUuid,
            'type' => $type,
            'mime' => $mimeType,
            'size' => $file->getSize(),
        ]);

        return $patientFile;
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

    private function sanitizeExtension(string $extension): string
    {
        $ext = strtolower(trim($extension, " \t\n\r\0\x0b."));
        $ext = preg_replace('/[^a-z0-9]/', '', $ext) ?? '';
        if ($ext === '' || !in_array($ext, self::SAFE_EXTENSIONS, true)) {
            return 'bin';
        }
        return $ext;
    }
}
