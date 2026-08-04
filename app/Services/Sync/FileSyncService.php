<?php

namespace App\Services\Sync;

use App\Services\Mobile\RemoteApiService;
use App\Domains\Sync\Models\SyncQueue;
use App\Domains\Sync\Services\SyncQueueService;
use App\Domains\Media\Models\PatientFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class FileSyncService
{
    private const RESUMABLE_THRESHOLD = 26214400; // 25 MB
    private const CHUNK_SIZE = 5242880;          // 5 MB

    public function __construct(
        private readonly RemoteApiService $api,
        private readonly SyncQueueService $queueService
    ) {}

    public function processItem(SyncQueue $item): bool
    {
        $file = PatientFile::withoutGlobalScope(\App\Domains\Auth\Scopes\DoctorIsolationScope::class)
            ->where('uuid', $item->entity_uuid)
            ->first();

        $offFile = null;
        if (!$file) {
            $offFile = DB::table('offline_files')->where('uuid', $item->entity_uuid)->first();
        }

        if (!$file && !$offFile && $item->operation !== 'delete') {
            $this->queueService->markFailed($item->id, 'Local file record not found');
            return false;
        }

        $this->queueService->markProcessing($item->id);

        try {
            switch ($item->operation) {
                case 'create':
                    $patientUuid = $file ? DB::table('patients')->where('id', $file->patient_id)->value('uuid') : ($offFile->patient_uuid ?? null);
                    $patientStatus = DB::table('patients')->where('uuid', $patientUuid)->value('sync_status');

                    if (!$patientUuid || $patientStatus !== 'synced') {
                        $this->queueService->markFailed($item->id, 'Parent patient not synced yet');
                        return false;
                    }

                    $filePath = $file ? $file->file_path : $offFile->local_path;
                    $absPath = Storage::disk('local')->path($filePath);

                    if (!file_exists($absPath)) {
                        if ($file) $file->update(['remote_uuid' => $file->uuid, 'sync_status' => 'synced']);
                        $this->queueService->markSynced($item->id);
                        return true;
                    }

                    $fileSize = filesize($absPath);
                    $sha256 = hash_file('sha256', $absPath);

                    // Update sha256 in local SQLite
                    if ($file) {
                        $file->update(['sha256' => $sha256]);
                    } else if ($offFile) {
                        DB::table('offline_files')->where('uuid', $offFile->uuid)->update(['sha256' => $sha256]);
                    }

                    // ── Task 2: SHA256 Deduplication Check ──────────────────
                    $remoteUuid = $this->checkServerSha256Deduplication($sha256);

                    // ── Task 1: Upload execution (Resumable vs Normal) ──────
                    if (!$remoteUuid) {
                        if ($fileSize > self::RESUMABLE_THRESHOLD) {
                            $remoteUuid = $this->uploadLargeFileResumable($absPath, $patientUuid, $file, $offFile);
                        } else {
                            $title = $file ? ($file->title ?? $file->file_name) : ($offFile->title ?? $offFile->original_name);
                            $response = $this->api->upload(
                                "/patients/{$patientUuid}/files",
                                ['file' => $absPath],
                                [
                                    'title' => $title,
                                    'desc'  => $file->desc ?? ($offFile->desc ?? ''),
                                    'sha256' => $sha256,
                                ]
                            );
                            $remoteUuid = $response['uuid'] ?? $response['file']['uuid'] ?? null;
                        }
                    }

                    if ($remoteUuid) {
                        if ($file) {
                            $file->update([
                                'remote_uuid' => $remoteUuid,
                                'sync_status' => 'synced',
                                'updated_at'  => now(),
                            ]);
                        }
                        if ($offFile) {
                            DB::table('offline_files')->where('uuid', $offFile->uuid)->update([
                                'remote_uuid' => $remoteUuid,
                                'sync_status' => 'synced',
                            ]);
                        }
                    }
                    break;

                case 'update':
                    if ($file && $file->remote_uuid) {
                        $this->api->put("/files/{$file->remote_uuid}", [
                            'title'    => $file->title,
                            'desc'     => $file->desc,
                            'category' => $file->category,
                        ]);
                        $file->update(['sync_status' => 'synced']);
                    }
                    break;

                case 'delete':
                    $remoteUuid = $file?->remote_uuid ?? $item->entity_uuid;
                    try {
                        $this->api->delete("/files/{$remoteUuid}");
                    } catch (Throwable $e) {
                        if (!str_contains($e->getMessage(), '404')) throw $e;
                    }

                    if ($file) $file->forceDelete();
                    if ($offFile) DB::table('offline_files')->where('uuid', $item->entity_uuid)->delete();
                    break;
            }

            $this->queueService->markSynced($item->id);
            return true;
        } catch (Throwable $e) {
            Log::warning("[FileSyncService] Item {$item->id} failed: " . $e->getMessage());
            $this->queueService->markFailed($item->id, $e->getMessage());
            return false;
        }
    }

    /**
     * Check if identical file already exists on server via SHA256 hash.
     */
    private function checkServerSha256Deduplication(string $sha256): ?string
    {
        try {
            $response = $this->api->get('/files/check-hash', ['hash' => $sha256]);
            if (!empty($response['exists']) && !empty($response['remote_uuid'])) {
                Log::info("[FileSyncService] SHA256 match found on server! Deduplicating binary upload for hash {$sha256}");
                return $response['remote_uuid'];
            }
        } catch (Throwable $e) {
            // Deduplication check endpoint optional on backend
        }
        return null;
    }

    /**
     * Upload large files (>25MB) using ChunkUploadController endpoints with resumable status checks.
     */
    public function uploadLargeFileResumable(string $absPath, string $patientUuid, ?PatientFile $file, ?object $offFile): ?string
    {
        $fileName = basename($absPath);
        $fileSize = filesize($absPath);
        $mimeType = ($file?->mime_type ?? $offFile?->mime_type) ?: (mime_content_type($absPath) ?: 'application/octet-stream');
        $title = $file ? ($file->title ?? $file->file_name) : ($offFile->title ?? $offFile->original_name);

        // Step 1: Init chunk upload session
        $initRes = $this->api->post('/chunk/init', [
            'file_name'  => $fileName,
            'file_size'  => $fileSize,
            'mime_type'  => $mimeType,
            'patient_id' => $patientUuid,
            'chunk_size' => self::CHUNK_SIZE,
            'metadata'   => ['title' => $title],
        ]);

        $uploadId = $initRes['upload_id'] ?? $initRes['uuid'] ?? null;
        if (!$uploadId) {
            throw new \RuntimeException("Failed to initialize resumable upload session for {$fileName}");
        }

        // Step 2: Check uploaded chunks status (Resumable check)
        $uploadedChunks = [];
        try {
            $statusRes = $this->api->get("/chunk/{$uploadId}/status");
            $uploadedChunks = $statusRes['uploaded_chunks'] ?? [];
        } catch (Throwable $e) {
            // New upload session
        }

        // Step 3: Stream and upload missing chunks
        $handle = fopen($absPath, 'rb');
        $totalChunks = (int) ceil($fileSize / self::CHUNK_SIZE);

        for ($chunkIndex = 0; $chunkIndex < $totalChunks; $chunkIndex++) {
            if (in_array($chunkIndex, $uploadedChunks)) {
                Log::info("[FileSyncService] Skipping already uploaded chunk #{$chunkIndex}/{$totalChunks} for {$uploadId}");
                fseek($handle, ($chunkIndex + 1) * self::CHUNK_SIZE);
                continue;
            }

            fseek($handle, $chunkIndex * self::CHUNK_SIZE);
            $chunkData = fread($handle, self::CHUNK_SIZE);

            $tmpChunkPath = tempnam(sys_get_temp_dir(), 'chunk_');
            file_put_contents($tmpChunkPath, $chunkData);

            try {
                $this->api->upload('/chunk/chunk', ['chunk' => $tmpChunkPath], [
                    'upload_id'   => $uploadId,
                    'chunk_index' => $chunkIndex,
                ]);
            } finally {
                @unlink($tmpChunkPath);
            }
        }
        fclose($handle);

        // Step 4: Complete and merge chunks
        $completeRes = $this->api->post('/chunk/complete', [
            'upload_id' => $uploadId,
        ]);

        return $completeRes['uuid'] ?? $completeRes['remote_uuid'] ?? null;
    }
}
