<?php

namespace App\Services\Upload;

use App\Domains\Media\Models\UploadSession;
use App\Domains\Patients\Models\Patient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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

        $patientUuid = $data['patient_uuid'] ?? null;
        if (!$patientUuid) {
            $patient = Patient::find($data['patient_id']);
            $patientUuid = $patient?->uuid;
        }

        $finalPath = $patientUuid
            ? "patients/{$patientUuid}/{%s}.{$extension}"
            : null;

        $uuid = (string) Str::uuid();

        $id = DB::table('upload_sessions')->insertGetId([
            'uuid'                  => $uuid,
            'patient_id'            => $data['patient_id'],
            'user_id'               => $userId,
            'original_name'         => $data['file_name'],
            'mime_type'             => $data['mime_type'],
            'extension'             => $extension ?: 'bin',
            'total_size'            => $data['file_size'],
            'total_chunks'          => $totalChunks,
            'chunk_size'            => $chunkSize,
            'status'                => 'pending',
            'checksum_algorithm'    => 'sha256',
            'metadata'              => json_encode($data['metadata'] ?? null),
            'disk'                  => 'local',
            'expires_at'            => now()->addHours(6),
            'final_path'            => $finalPath ? sprintf($finalPath, $uuid) : null,
            'received_chunk_indexes'=> '[]',
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);

        Log::channel('upload')->info('session:init', [
            'session'   => $uuid,
            'user'       => $userId,
            'patient'    => $data['patient_id'],
            'file'       => $data['file_name'],
            'size'       => $data['file_size'],
            'chunks'     => $totalChunks,
            'chunk_size' => $chunkSize,
            'final_path' => $finalPath ? sprintf($finalPath, $uuid) : null,
        ]);

        return UploadSession::where('uuid', $uuid)->firstOrFail();
    }

    /**
     * Atomic transition pending -> uploading using row-level lock.
     *
     * IMPORTANT: This is intentionally a fast-path optimisation.
     * When the session is already 'uploading' (the common case for every chunk
     * after the first), we return immediately without acquiring a DB lock.
     * The previous implementation locked every chunk request, causing deadlocks
     * and lock-wait timeouts under parallel uploads (4 concurrent slots).
     *
     * The exclusive lock is only taken when transitioning from 'pending',
     * which happens exactly once per upload session.
     */
    public function ensureUploading(UploadSession $session): UploadSession
    {
        // Fast path: already uploading — no lock needed, just return the session.
        if ($session->status === 'uploading') {
            return $session;
        }

        // Slow path: need to transition from pending -> uploading (happens once).
        return DB::transaction(function () use ($session) {
            $locked = UploadSession::where('id', $session->id)->lockForUpdate()->first();
            if (!$locked) {
                return $session;
            }
            if ($locked->status === 'pending') {
                $locked->update(['status' => 'uploading']);
                Log::channel('upload')->info('session:transition pending->uploading', [
                    'session' => $locked->uuid,
                ]);
                return $locked->fresh();
            }
            return $locked;
        });
    }

    public function markCompleted(string $uuid, ?string $checksum): UploadSession
    {
        return DB::transaction(function () use ($uuid, $checksum) {
            $session = UploadSession::where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            $session->update([
                'status'         => 'completed',
                'final_checksum' => $checksum,
            ]);
            Log::channel('upload')->info('session:transition ->completed', [
                'session' => $uuid,
            ]);
            return $session;
        });
    }

    public function markFailed(string $uuid): UploadSession
    {
        return DB::transaction(function () use ($uuid) {
            $session = UploadSession::where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            $session->update(['status' => 'failed']);
            return $session;
        });
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
