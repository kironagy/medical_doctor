<?php

namespace App\Repositories;

use App\Contracts\Repositories\OfflineFileRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OfflineFileRepository implements OfflineFileRepositoryInterface
{
    /**
     * Table used for local SQLite storage.
     */
    private const TABLE = 'offline_files';

    /**
     * Store a new pending upload record in the local SQLite database.
     */
    public function create(array $data): array
    {
        $record = [
            'uuid'          => $data['uuid'],
            'patient_uuid'  => $data['patient_uuid'],
            'local_path'    => $data['local_path'],
            'original_name' => $data['original_name'],
            'title'         => $data['title'] ?? $data['original_name'],
            'desc'          => $data['desc'] ?? '',
            'category'      => $data['category'] ?? null,
            'date'          => $data['date'] ?? null,
            'mime_type'     => $data['mime_type'] ?? null,
            'extension'     => $data['extension'] ?? null,
            'size'          => $data['size'] ?? 0,
            'hash'          => $data['hash'] ?? null,
            'sync_status'   => 'pending_upload',
            'retry_count'   => 0,
            'created_at'    => now(),
            'updated_at'    => now(),
        ];

        DB::table(self::TABLE)->insert($record);

        Log::info('[OfflineFile] Created pending upload record', [
            'uuid'         => $data['uuid'],
            'patient_uuid' => $data['patient_uuid'],
            'original_name' => $data['original_name'],
            'size'         => $data['size'] ?? 0,
        ]);

        return $this->findByUuid($data['uuid']) ?? $record;
    }

    /**
     * Find a single offline file by its local UUID.
     */
    public function findByUuid(string $uuid): ?array
    {
        $record = DB::table(self::TABLE)->where('uuid', $uuid)->first();
        return $record ? (array) $record : null;
    }

    /**
     * Return all files with a given sync status.
     */
    public function findByStatus(string $status): array
    {
        return DB::table(self::TABLE)
            ->where('sync_status', $status)
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(fn ($r) => (array) $r)
            ->toArray();
    }

    /**
     * Return all pending uploads (sync_status = pending_upload),
     * ordered by created_at ascending (oldest first).
     */
    public function findPending(): array
    {
        return $this->findByStatus('pending_upload');
    }

    /**
     * Update the sync_status and mark the file as being uploaded.
     */
    public function markUploading(string $uuid): void
    {
        DB::table(self::TABLE)
            ->where('uuid', $uuid)
            ->update([
                'sync_status' => 'uploading',
                'updated_at'  => now(),
            ]);
    }

    /**
     * Mark the file as successfully synced to the remote server.
     */
    public function markSynced(string $uuid, string $remoteUuid): void
    {
        DB::table(self::TABLE)
            ->where('uuid', $uuid)
            ->update([
                'sync_status' => 'synced',
                'remote_uuid' => $remoteUuid,
                'uploaded_at' => now(),
                'updated_at'  => now(),
            ]);

        Log::info('[OfflineFile] Marked as synced', [
            'uuid'        => $uuid,
            'remote_uuid' => $remoteUuid,
        ]);
    }

    /**
     * Mark the file as failed with an error message.
     */
    public function markFailed(string $uuid, string $errorMessage): void
    {
        DB::table(self::TABLE)
            ->where('uuid', $uuid)
            ->update([
                'sync_status'   => 'failed',
                'error_message' => $errorMessage,
                'updated_at'    => now(),
            ]);

        Log::warning('[OfflineFile] Marked as failed', [
            'uuid'    => $uuid,
            'error'   => $errorMessage,
        ]);
    }

    /**
     * Increment retry count for a failed upload.
     */
    public function incrementRetry(string $uuid): void
    {
        DB::table(self::TABLE)
            ->where('uuid', $uuid)
            ->increment('retry_count');
    }

    /**
     * Find all non-synced offline files for a given patient UUID.
     */
    public function findByPatientUuid(string $patientUuid): array
    {
        return DB::table(self::TABLE)
            ->where('patient_uuid', $patientUuid)
            ->whereIn('sync_status', ['pending_upload', 'uploading', 'failed'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn ($r) => (array) $r)
            ->toArray();
    }

    /**
     * Delete a local offline file record.
     */
    public function delete(string $uuid): void
    {
        DB::table(self::TABLE)->where('uuid', $uuid)->delete();

        Log::info('[OfflineFile] Deleted record', ['uuid' => $uuid]);
    }

    /**
     * Count files by sync status.
     */
    public function countByStatus(string $status): int
    {
        return DB::table(self::TABLE)
            ->where('sync_status', $status)
            ->count();
    }
}
