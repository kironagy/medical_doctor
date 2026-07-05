<?php

namespace App\Services\Upload;

use App\Domains\Media\Models\UploadSession;
use App\Domains\Patients\Models\Patient;
use Illuminate\Support\Facades\DB;

class UploadSessionService
{
    public function __construct(
        private readonly UploadValidationService $validationService,
    ) {}

    public function create(array $data, int $userId): UploadSession
    {
        $this->validationService->validateInit($data);

        $chunkSize = min($data['chunk_size'] ?? 5 * 1024 * 1024, 50 * 1024 * 1024);
        $chunkSize = max($chunkSize, 1024 * 1024);
        $totalChunks = (int) ceil($data['file_size'] / $chunkSize);
        $extension = pathinfo($data['file_name'], PATHINFO_EXTENSION);
        $extension = strtolower(trim($extension, ". \t\n\r\0\x0b"));

        $session = UploadSession::create([
            'patient_id' => $data['patient_id'],
            'user_id' => $userId,
            'original_name' => $data['file_name'],
            'mime_type' => $data['mime_type'],
            'extension' => $extension ?: 'bin',
            'total_size' => $data['file_size'],
            'total_chunks' => $totalChunks,
            'chunk_size' => $chunkSize,
            'status' => 'pending',
            'checksum_algorithm' => 'sha256',
            'metadata' => $data['metadata'] ?? null,
            'disk' => 'local',
            'expires_at' => now()->addHours(6),
            'received_chunk_indexes' => [], // initialize empty array
        ]);

        DB::table('upload_sessions')
            ->where('id', $session->id)
            ->update(['uuid' => $session->uuid]);

        // Compute final file path for direct-write optimization
        $patientUuid = $data['patient_uuid'] ?? null;
        if (!$patientUuid) {
            $patient = Patient::find($data['patient_id']);
            $patientUuid = $patient?->uuid;
        }
        if ($patientUuid) {
            $finalPath = "patients/{$patientUuid}/{$session->uuid}.{$extension}";
            // Use direct DB update to avoid triggering mass-assignment issues
            DB::table('upload_sessions')
                ->where('id', $session->id)
                ->update(['final_path' => $finalPath]);
        }

        return $session;
    }

    public function markUploading(string $uuid): UploadSession
    {
        $session = $this->findOrFail($uuid);
        $session->update(['status' => 'uploading']);
        return $session;
    }

    public function markCompleted(string $uuid, string $checksum): UploadSession
    {
        $session = $this->findOrFail($uuid);
        $session->update([
            'status' => 'completed',
            'final_checksum' => $checksum,
        ]);
        return $session;
    }

    public function markFailed(string $uuid): UploadSession
    {
        $session = $this->findOrFail($uuid);
        $session->update(['status' => 'failed']);
        return $session;
    }

    public function findOrFail(string $uuid): UploadSession
    {
        return UploadSession::where('uuid', $uuid)->firstOrFail();
    }

    public function ownedByUser(UploadSession $session, int $userId): bool
    {
        return $session->user_id === $userId;
    }
}
