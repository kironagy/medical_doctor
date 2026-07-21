<?php

namespace App\Observers;

use App\Domains\Media\Models\PatientFile;
use App\Domains\Media\Jobs\OptimizeVideoForStreaming;
use App\Services\SyncQueueService;
use Illuminate\Support\Facades\Log;

class PatientFileObserver
{
    public function created(PatientFile $file)
    {
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
}
