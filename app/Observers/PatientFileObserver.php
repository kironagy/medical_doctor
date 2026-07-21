<?php

namespace App\Observers;

use App\Domains\Media\Models\PatientFile;
use App\Domains\Media\Jobs\OptimizeVideoForStreaming;
use App\Models\SyncQueueItem;
use App\Services\SyncQueueService;
use Illuminate\Support\Facades\Log;

class PatientFileObserver
{
    /**
     * Check if a sync operation for this record already exists.
     * Prevents duplicate sync entries (Observer + Upload Controller conflict).
     */
    private function hasExistingPendingOperation(string $recordUuid, string $operation): bool
    {
        return SyncQueueItem::where('record_uuid', $recordUuid)
            ->where('operation', $operation)
            ->where('status', 'pending')
            ->exists();
    }

    public function created(PatientFile $file)
    {
        // Dedup check: skip if already enqueued (e.g. by UploadController)
        if ($this->hasExistingPendingOperation($file->uuid, 'create')) {
            Log::info('[PatientFileObserver] Duplicate create event skipped for file: ' . $file->uuid);
            return;
        }

        // Enqueue sync for locally-created files so they get pushed to the
        // remote API during the next background sync.
        // This is critical for the mobile app: when a doctor uploads a file
        // on their phone, it must reach the website via the API.
        try {
            $syncQueue = app(SyncQueueService::class);
            $syncQueue->enqueueOperation(
                'PatientFile',
                'create',
                $file->uuid,
                [
                    'patient_uuid' => $file->patient?->uuid,
                    'local_path'   => $file->file_path,
                    'file_name'    => $file->file_name,
                    'mime_type'    => $file->mime_type,
                    'size'         => $file->size,
                    'title'        => $file->title,
                    'desc'         => $file->desc,
                    'category'     => $file->category,
                    'upload_status' => $file->upload_status,
                ]
            );
            Log::info('[PatientFileObserver] Enqueued sync for new file', [
                'uuid' => $file->uuid,
                'patient_uuid' => $file->patient?->uuid,
            ]);
        } catch (\Throwable $e) {
            // Silent fail — sync queue is best-effort
            Log::warning('[PatientFileObserver] Failed to enqueue sync: ' . $e->getMessage());
        }

        // Existing video optimization logic
        if (str_starts_with($file->mime_type, 'video/')) {
            OptimizeVideoForStreaming::dispatch($file->id);
        }
    }

    /**
     * Handle the PatientFile "deleted" event (SoftDeletes).
     */
    public function deleted(PatientFile $file): void
    {
        // Skip if this is a force delete (handled by FileAccessController directly)
        // isForceDeleting() is only available in Laravel 10+
        if (method_exists($file, 'isForceDeleting') && $file->isForceDeleting()) {
            return;
        }

        // Dedup check
        if ($this->hasExistingPendingOperation($file->uuid, 'delete')) {
            return;
        }

        try {
            $syncQueue = app(SyncQueueService::class);
            $syncQueue->enqueueOperation(
                'PatientFile',
                'delete',
                $file->uuid,
                [
                    'patient_uuid' => $file->patient?->uuid,
                ]
            );
            Log::info('[PatientFileObserver] Enqueued sync for deleted file: ' . $file->uuid);
        } catch (\Throwable $e) {
            Log::warning('[PatientFileObserver] Failed to enqueue delete sync: ' . $e->getMessage());
        }
    }

    /**
     * Handle the PatientFile "restored" event (SoftDeletes).
     */
    public function restored(PatientFile $file): void
    {
        // When a file is restored locally, we need to push this to the remote.
        // The remote will need to restore the soft-deleted record.
        try {
            $syncQueue = app(SyncQueueService::class);
            $syncQueue->enqueueOperation(
                'PatientFile',
                'update',  // Treat restore as an update (re-activates)
                $file->uuid,
                [
                    'patient_uuid' => $file->patient?->uuid,
                    'restored_at' => now()->toIso8601String(),
                ]
            );
            Log::info('[PatientFileObserver] Enqueued sync for restored file: ' . $file->uuid);
        } catch (\Throwable $e) {
            Log::warning('[PatientFileObserver] Failed to enqueue restore sync: ' . $e->getMessage());
        }
    }
}
